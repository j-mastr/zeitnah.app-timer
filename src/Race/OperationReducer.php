<?php

namespace App\Race;

/**
 * Applies client operations to a race state. This is the authoritative
 * implementation; the frontend contains a line-by-line JavaScript mirror
 * (applyOp in frontend/index.html) used for optimistic updates. Keep both in sync.
 *
 * State shape:
 *   name: ?string, archived: bool, sport: string,
 *   participants: list<{id, name}>, ranking: list<participantId>, captures: list<{id, ts, tzOffset: ?int, participantId: ?string}>
 *
 * A capture timestamp is the pair `ts` (Unix milliseconds, the absolute instant including
 * the date) and `tzOffset` (minutes east of UTC on the recording device, null when the
 * recording client did not report one), so it can always be rendered as a full local
 * timestamp with date and time zone.
 *
 * `sport` selects the UI text set (see TEXTS in frontend/index.html); the reducer only
 * validates it against SPORTS, it carries no other meaning server-side.
 *
 * Operations are lenient about references (e.g. assigning a capture to a participant that
 * was deleted concurrently is a no-op) so that buffered offline operations can
 * always be replayed, but strict about their shape.
 */
final class OperationReducer
{
    public const TYPES = [
        'race.rename', 'race.archive', 'race.setSport',
        'participants.add', 'participant.rename', 'participant.delete',
        'ranking.add', 'ranking.remove', 'ranking.move',
        'capture.add', 'capture.assign', 'capture.delete',
        'state.merge',
    ];

    // Mirrors SPORTS in frontend/index.html.
    public const SPORTS = ['generic', 'sailing', 'running', 'swimming', 'motor'];

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';
    private const MAX_PARTICIPANT_NAME = 60;
    private const MAX_RACE_NAME = 80;

    public static function emptyState(): array
    {
        return ['name' => null, 'archived' => false, 'sport' => 'generic', 'participants' => [], 'ranking' => [], 'captures' => []];
    }

    /**
     * @throws InvalidOperationException
     */
    public function apply(array $state, array $op): array
    {
        $type = $op['type'] ?? null;
        if (!is_string($type)) {
            throw new InvalidOperationException('invalid_type');
        }

        switch ($type) {
            case 'race.rename':
                $name = $op['name'] ?? null;
                $state['name'] = (null === $name || (is_string($name) && '' === self::clean($name)))
                    ? null
                    : self::name($name, self::MAX_RACE_NAME, 'name');

                return $state;

            case 'race.archive':
                $state['archived'] = true;
                // An archived race is read-only, so the expected crossing order is meaningless.
                $state['ranking'] = [];

                return $state;

            case 'race.setSport':
                $sport = $op['sport'] ?? null;
                if (!is_string($sport) || !in_array($sport, self::SPORTS, true)) {
                    throw new InvalidOperationException('invalid_sport');
                }
                $state['sport'] = $sport;

                return $state;

            case 'participants.add':
                $participants = $op['participants'] ?? null;
                if (!is_array($participants) || !array_is_list($participants) || count($participants) > 2000) {
                    throw new InvalidOperationException('invalid_participants');
                }
                foreach ($participants as $participant) {
                    $id = self::id(is_array($participant) ? ($participant['id'] ?? null) : null, 'participant_id');
                    $name = self::name(is_array($participant) ? ($participant['name'] ?? null) : null, self::MAX_PARTICIPANT_NAME, 'participant_name');
                    if (null !== self::findParticipant($state, $id) || null !== self::findParticipantByName($state, $name)) {
                        continue;
                    }
                    $state['participants'][] = ['id' => $id, 'name' => $name];
                }

                return $state;

            case 'participant.rename':
                $id = self::id($op['participantId'] ?? null, 'participant_id');
                $name = self::name($op['name'] ?? null, self::MAX_PARTICIPANT_NAME, 'participant_name');
                $index = self::findParticipant($state, $id);
                if (null !== $index) {
                    $state['participants'][$index]['name'] = $name;
                }

                return $state;

            case 'participant.delete':
                $id = self::id($op['participantId'] ?? null, 'participant_id');
                $state['participants'] = array_values(array_filter($state['participants'], static fn ($b) => $b['id'] !== $id));
                $state['ranking'] = self::without($state['ranking'], $id);

                return $state;

            case 'ranking.add':
                $id = self::id($op['participantId'] ?? null, 'participant_id');
                if (null !== self::findParticipant($state, $id) && !in_array($id, $state['ranking'], true)) {
                    $state['ranking'][] = $id;
                }

                return $state;

            case 'ranking.remove':
                $id = self::id($op['participantId'] ?? null, 'participant_id');
                $state['ranking'] = self::without($state['ranking'], $id);

                return $state;

            case 'ranking.move':
                $id = self::id($op['participantId'] ?? null, 'participant_id');
                $before = self::optionalId($op['beforeId'] ?? null, 'before_id');
                if (!in_array($id, $state['ranking'], true)) {
                    return $state;
                }
                $ranking = self::without($state['ranking'], $id);
                $position = (null === $before || $before === $id) ? false : array_search($before, $ranking, true);
                if (false === $position) {
                    $ranking[] = $id;
                } else {
                    array_splice($ranking, $position, 0, [$id]);
                }
                $state['ranking'] = $ranking;

                return $state;

            case 'capture.add':
                $capture = $op['capture'] ?? null;
                if (!is_array($capture)) {
                    throw new InvalidOperationException('invalid_capture');
                }
                $id = self::id($capture['id'] ?? null, 'capture_id');
                $ts = self::timestamp($capture['ts'] ?? null);
                $tzOffset = self::tzOffset($capture['tzOffset'] ?? null);
                $participantId = self::optionalId($capture['participantId'] ?? null, 'participant_id');
                if (null !== self::findCapture($state, $id)) {
                    return $state;
                }
                if (null !== $participantId && null === self::findParticipant($state, $participantId)) {
                    $participantId = null;
                }
                $state['captures'][] = ['id' => $id, 'ts' => $ts, 'tzOffset' => $tzOffset, 'participantId' => $participantId];
                if (null !== $participantId) {
                    $state['ranking'] = self::without($state['ranking'], $participantId);
                }

                return $state;

            case 'capture.assign':
                $captureId = self::id($op['captureId'] ?? null, 'capture_id');
                $participantId = self::optionalId($op['participantId'] ?? null, 'participant_id');
                $index = self::findCapture($state, $captureId);
                if (null === $index || (null !== $participantId && null === self::findParticipant($state, $participantId))) {
                    return $state;
                }
                $state['captures'][$index]['participantId'] = $participantId;

                return $state;

            case 'capture.delete':
                $captureId = self::id($op['captureId'] ?? null, 'capture_id');
                $state['captures'] = array_values(array_filter($state['captures'], static fn ($c) => $c['id'] !== $captureId));

                return $state;

            case 'state.merge':
                return $this->merge($state, $op['state'] ?? null);

            default:
                throw new InvalidOperationException('unknown_type');
        }
    }

    /**
     * Merges another state into this one without ever removing anything: participants are
     * matched by id or (case-insensitive) name, missing participants are appended to the
     * ranking, captures are added unless their id already exists.
     */
    private function merge(array $state, mixed $source): array
    {
        if (!is_array($source)) {
            throw new InvalidOperationException('invalid_state');
        }
        $participants = $source['participants'] ?? [];
        $ranking = $source['ranking'] ?? [];
        $captures = $source['captures'] ?? [];
        if (!is_array($participants) || !is_array($ranking) || !is_array($captures)
            || count($participants) > 5000 || count($ranking) > 5000 || count($captures) > 20000) {
            throw new InvalidOperationException('invalid_state');
        }

        $idMap = [];
        foreach ($participants as $participant) {
            $id = self::id(is_array($participant) ? ($participant['id'] ?? null) : null, 'participant_id');
            $name = self::name(is_array($participant) ? ($participant['name'] ?? null) : null, self::MAX_PARTICIPANT_NAME, 'participant_name');
            $index = self::findParticipant($state, $id) ?? self::findParticipantByName($state, $name);
            if (null === $index) {
                $state['participants'][] = ['id' => $id, 'name' => $name];
                $idMap[$id] = $id;
            } else {
                $idMap[$id] = $state['participants'][$index]['id'];
            }
        }

        foreach ($ranking as $participantId) {
            $participantId = self::id($participantId, 'participant_id');
            $mapped = $idMap[$participantId] ?? null;
            if (null !== $mapped && !in_array($mapped, $state['ranking'], true)) {
                $state['ranking'][] = $mapped;
            }
        }

        foreach ($captures as $capture) {
            if (!is_array($capture)) {
                throw new InvalidOperationException('invalid_capture');
            }
            $id = self::id($capture['id'] ?? null, 'capture_id');
            $ts = self::timestamp($capture['ts'] ?? null);
            $tzOffset = self::tzOffset($capture['tzOffset'] ?? null);
            $participantId = self::optionalId($capture['participantId'] ?? null, 'participant_id');
            if (null !== self::findCapture($state, $id)) {
                continue;
            }
            $state['captures'][] = ['id' => $id, 'ts' => $ts, 'tzOffset' => $tzOffset, 'participantId' => null !== $participantId ? ($idMap[$participantId] ?? null) : null];
        }

        $name = $source['name'] ?? null;
        if (null === $state['name'] && is_string($name) && '' !== self::clean($name)) {
            $state['name'] = self::name($name, self::MAX_RACE_NAME, 'name');
        }

        return $state;
    }

    private static function clean(string $value): string
    {
        return preg_replace('/\s+/u', ' ', preg_replace('/^\s+|\s+$/u', '', $value) ?? '') ?? '';
    }

    private static function key(string $name): string
    {
        return mb_strtolower(self::clean($name));
    }

    private static function id(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match(self::ID_PATTERN, $value)) {
            throw new InvalidOperationException('invalid_'.$field);
        }

        return $value;
    }

    private static function optionalId(mixed $value, string $field): ?string
    {
        return null === $value ? null : self::id($value, $field);
    }

    private static function name(mixed $value, int $max, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidOperationException('invalid_'.$field);
        }
        $clean = self::clean($value);
        if ('' === $clean || mb_strlen($clean) > $max) {
            throw new InvalidOperationException('invalid_'.$field);
        }

        return $clean;
    }

    private static function timestamp(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 100_000_000_000_000) {
            throw new InvalidOperationException('invalid_ts');
        }

        return $value;
    }

    /** Time zone of the recording device in minutes east of UTC; null when unknown (older clients). */
    private static function tzOffset(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if (!is_int($value) || $value < -900 || $value > 900) {
            throw new InvalidOperationException('invalid_tz_offset');
        }

        return $value;
    }

    private static function findParticipant(array $state, string $id): ?int
    {
        foreach ($state['participants'] as $i => $participant) {
            if ($participant['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private static function findParticipantByName(array $state, string $name): ?int
    {
        $key = self::key($name);
        foreach ($state['participants'] as $i => $participant) {
            if (self::key($participant['name']) === $key) {
                return $i;
            }
        }

        return null;
    }

    private static function findCapture(array $state, string $id): ?int
    {
        foreach ($state['captures'] as $i => $capture) {
            if ($capture['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private static function without(array $list, string $value): array
    {
        return array_values(array_filter($list, static fn ($v) => $v !== $value));
    }
}

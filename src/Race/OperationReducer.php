<?php

namespace App\Race;

/**
 * Applies client operations to a race state. This is the authoritative
 * implementation; the frontend contains a line-by-line JavaScript mirror
 * (applyOp in frontend/index.html) used for optimistic updates. Keep both in sync.
 *
 * State shape:
 *   schema: int (SCHEMA_VERSION), name: ?string, archived: bool, sport: string,
 *   participants: list<{id, name}>, kinds: list<{id, name, role}>,
 *   worksets: list<{id, number: int, name: ?string, ranking: list<participantId>, captureKind: string}>,
 *   captures: list<{id, ts, tzOffset: ?int, targets: list<{type: 'participant', id}>, kind: string, worksetId: ?string}>
 *
 * A capture timestamp is the pair `ts` (Unix milliseconds, the absolute instant including
 * the date) and `tzOffset` (minutes east of UTC on the recording device, null when the
 * recording client did not report one), so it can always be rendered as a full local
 * timestamp with date and time zone.
 *
 * A capture concerns its `targets`: references `{type, id}` to what it applies to — for now
 * participants only (groups follow, see docs/groups.md). None = unassigned, several = e.g. a
 * protest involving several participants. Unknown participants are dropped when a capture is
 * added; deleting a participant later keeps its reference on the captures.
 *
 * Every capture has a `kind`: one of the built-in kinds (start, split, finish; the default
 * is finish) or the id of a custom kind in `kinds`. A custom kind has the role `split` (a
 * point the participants pass) or `marker` (an annotation such as a protest). A capture takes
 * its participants out of the ranking unless its kind is a marker; an unknown kind id (e.g. a
 * custom kind deleted concurrently) counts as a marker.
 *
 * A workset (a "station" in the UI) holds a ranking — the participants approaching, in their
 * expected crossing order — and the kind its captures get. A race starts without worksets;
 * the first one in the list is the default. Each workset has a `number` fixed when it is
 * created (one more than the highest existing number), which names it until it is renamed.
 * A capture records the workset it was taken on (`worksetId`, null without one) and only takes
 * its participants out of that workset's ranking. Deleted worksets keep their id on captures.
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
        'capture.add', 'capture.assign', 'capture.delete', 'capture.setKind', 'capture.target.add', 'capture.target.remove',
        'kind.add', 'kind.update', 'kind.delete',
        'workset.add', 'workset.rename', 'workset.delete', 'workset.makeDefault', 'workset.setKind',
        'workset.ranking.add', 'workset.ranking.remove', 'workset.ranking.move',
        'state.merge',
    ];

    /**
     * Version of the state shape and the operation semantics. Raise it (and add a migration
     * step to MIGRATIONS and to the frontend's mirror) whenever a client of the previous version
     * could no longer apply the events correctly: a new state shape, a new operation type,
     * changed semantics. Clients of an older version are refused (`client_outdated`).
     * Mirrors SCHEMA_VERSION in frontend/index.html.
     */
    public const SCHEMA_VERSION = 2;

    /** Migration steps: version => method turning a state of that version into the next one. */
    private const MIGRATIONS = [1 => 'toVersion2'];

    /** Target types a capture may reference (groups follow, see docs/groups.md). */
    public const TARGET_TYPES = ['participant'];
    private const MAX_TARGETS = 500;

    // Mirrors SPORTS in frontend/index.html.
    public const SPORTS = ['generic', 'sailing', 'running', 'swimming', 'motor'];

    public const DEFAULT_SPORT = 'generic';

    // Mirrors BUILTIN_KINDS / CUSTOM_KIND_ROLES in frontend/index.html. A built-in kind is its own role.
    public const BUILTIN_KINDS = ['start', 'split', 'finish'];
    public const DEFAULT_KIND = 'finish';
    public const CUSTOM_KIND_ROLES = ['split', 'marker'];

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';
    private const MAX_PARTICIPANT_NAME = 60;
    private const MAX_RACE_NAME = 80;
    private const MAX_KIND_NAME = 40;
    private const MAX_WORKSET_NAME = 40;
    private const MAX_WORKSET_NUMBER = 1_000_000;

    public static function emptyState(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION, 'name' => null, 'archived' => false, 'sport' => self::DEFAULT_SPORT, 'participants' => [],
            'kinds' => [], 'worksets' => [], 'captures' => [],
        ];
    }

    /**
     * Whether a client announcing this schema version (`hello` / the `schema` request
     * parameter; missing = 1, i.e. a client from before versioning) is too old for this server.
     */
    public static function isClientOutdated(mixed $schema): bool
    {
        $version = is_int($schema) || (is_string($schema) && ctype_digit($schema)) ? (int) $schema : 1;

        return $version < self::SCHEMA_VERSION;
    }

    /**
     * Brings a stored state up to SCHEMA_VERSION. A state without `schema` was stored before
     * versioning (version 1): the fields it may lack (sport, kinds, worksets, the kind and
     * workset of each capture) are filled in, and the race-wide ranking and selected kind of
     * earlier versions are dropped (a race starts without worksets). Then the migration steps run.
     */
    public static function upgrade(array $state): array
    {
        if (!isset($state['schema'])) {
            // Stored before versioning: version 1, possibly still lacking fields added before it.
            unset($state['ranking'], $state['captureKind']);
            $state = ['schema' => 1] + $state + self::emptyState();
            foreach ($state['captures'] as $i => $capture) {
                $state['captures'][$i] += ['tzOffset' => null, 'kind' => self::DEFAULT_KIND, 'worksetId' => null];
            }
        }

        return self::migrate($state);
    }

    /**
     * Runs the migration steps from the state's schema version up to SCHEMA_VERSION.
     *
     * @throws \UnexpectedValueException for a state of a newer version than this code knows
     */
    public static function migrate(array $state): array
    {
        $version = $state['schema'] ?? 1;
        if (!is_int($version) || $version < 1 || $version > self::SCHEMA_VERSION) {
            throw new \UnexpectedValueException('Unsupported state schema version: '.json_encode($version));
        }
        $state = self::runMigrations($state, $version);
        $state['schema'] = self::SCHEMA_VERSION;

        return $state;
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
                foreach ($state['worksets'] as $i => $workset) {
                    $state['worksets'][$i]['ranking'] = [];
                }

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
                foreach ($state['worksets'] as $i => $workset) {
                    $state['worksets'][$i]['ranking'] = self::without($workset['ranking'], $id);
                }

                return $state;

            case 'capture.add':
                $capture = $op['capture'] ?? null;
                if (!is_array($capture)) {
                    throw new InvalidOperationException('invalid_capture');
                }
                $id = self::id($capture['id'] ?? null, 'capture_id');
                $ts = self::timestamp($capture['ts'] ?? null);
                $tzOffset = self::tzOffset($capture['tzOffset'] ?? null);
                $targets = self::captureTargets($capture);
                $kind = self::captureKind($capture['kind'] ?? null);
                $worksetId = self::optionalId($capture['worksetId'] ?? null, 'workset_id');
                if (null !== self::findCapture($state, $id)) {
                    return $state;
                }
                $targets = self::existingTargets($state, $targets);
                $state['captures'][] = [
                    'id' => $id, 'ts' => $ts, 'tzOffset' => $tzOffset, 'targets' => $targets, 'kind' => $kind, 'worksetId' => $worksetId,
                ];
                // A marker (e.g. a protest) annotates participants without them passing the point.
                // Only the capturing workset's ranking is affected.
                $index = null === $worksetId ? null : self::findWorkset($state, $worksetId);
                if (null !== $index && 'marker' !== self::kindRole($state, $kind)) {
                    foreach ($targets as $target) {
                        $state['worksets'][$index]['ranking'] = self::without($state['worksets'][$index]['ranking'], $target['id']);
                    }
                }

                return $state;

            case 'capture.assign':
                // Replaces all targets: with `targets` (unknown ones dropped), or with the single
                // `participantId` of earlier versions (null = none; an unknown one changes nothing).
                $captureId = self::id($op['captureId'] ?? null, 'capture_id');
                if (isset($op['targets'])) {
                    $targets = self::targetList($op['targets']);
                    $index = self::findCapture($state, $captureId);
                    if (null !== $index) {
                        $state['captures'][$index]['targets'] = self::existingTargets($state, $targets);
                    }

                    return $state;
                }
                $participantId = self::optionalId($op['participantId'] ?? null, 'participant_id');
                $index = self::findCapture($state, $captureId);
                if (null === $index || (null !== $participantId && null === self::findParticipant($state, $participantId))) {
                    return $state;
                }
                $state['captures'][$index]['targets'] = null === $participantId ? [] : [['type' => 'participant', 'id' => $participantId]];

                return $state;

            case 'capture.target.add':
                $captureId = self::id($op['captureId'] ?? null, 'capture_id');
                $target = self::target($op['target'] ?? null);
                $index = self::findCapture($state, $captureId);
                $targets = null === $index ? [] : $state['captures'][$index]['targets'];
                if (null !== $index && null !== self::findParticipant($state, $target['id'])
                    && !in_array($target, $targets, true) && count($targets) < self::MAX_TARGETS) {
                    $state['captures'][$index]['targets'][] = $target;
                }

                return $state;

            case 'capture.target.remove':
                $captureId = self::id($op['captureId'] ?? null, 'capture_id');
                $target = self::target($op['target'] ?? null);
                $index = self::findCapture($state, $captureId);
                if (null !== $index) {
                    $state['captures'][$index]['targets'] = array_values(array_filter(
                        $state['captures'][$index]['targets'], static fn ($t) => $t !== $target,
                    ));
                }

                return $state;

            case 'capture.delete':
                $captureId = self::id($op['captureId'] ?? null, 'capture_id');
                $state['captures'] = array_values(array_filter($state['captures'], static fn ($c) => $c['id'] !== $captureId));

                return $state;

            case 'capture.setKind':
                $captureId = self::id($op['captureId'] ?? null, 'capture_id');
                $kind = self::captureKind($op['kind'] ?? null);
                $index = self::findCapture($state, $captureId);
                if (null !== $index) {
                    $state['captures'][$index]['kind'] = $kind;
                }

                return $state;

            case 'kind.add':
                $kind = self::customKind($op['kind'] ?? null);
                if (null === self::findKind($state, $kind['id']) && null === self::findKindByName($state, $kind['name'])) {
                    $state['kinds'][] = $kind;
                }

                return $state;

            case 'kind.update':
                $id = self::id($op['kindId'] ?? null, 'kind_id');
                $name = self::name($op['name'] ?? null, self::MAX_KIND_NAME, 'kind_name');
                $role = self::kindRoleValue($op['role'] ?? null);
                $index = self::findKind($state, $id);
                if (null !== $index) {
                    $state['kinds'][$index] = ['id' => $id, 'name' => $name, 'role' => $role];
                }

                return $state;

            case 'kind.delete':
                $id = self::id($op['kindId'] ?? null, 'kind_id');
                // Captures keep the id, like they keep the id of a deleted participant.
                $state['kinds'] = array_values(array_filter($state['kinds'], static fn ($k) => $k['id'] !== $id));
                foreach ($state['worksets'] as $i => $workset) {
                    if ($workset['captureKind'] === $id) {
                        $state['worksets'][$i]['captureKind'] = self::DEFAULT_KIND;
                    }
                }

                return $state;

            case 'workset.add':
                $workset = $op['workset'] ?? null;
                if (!is_array($workset)) {
                    throw new InvalidOperationException('invalid_workset');
                }
                $id = self::id($workset['id'] ?? null, 'workset_id');
                $name = self::optionalName($workset['name'] ?? null, self::MAX_WORKSET_NAME, 'workset_name');
                $number = self::worksetNumber($workset['number'] ?? null);
                $ranking = self::rankingList($workset['ranking'] ?? null);
                $kind = self::captureKind($workset['captureKind'] ?? null);
                $before = self::optionalId($op['beforeId'] ?? null, 'before_id');
                if (null !== self::findWorkset($state, $id) || (null !== $name && null !== self::findWorksetByName($state, $name))) {
                    return $state;
                }
                // The optional fields restore a deleted workset exactly (undo).
                $entry = [
                    'id' => $id,
                    'number' => $number ?? self::nextWorksetNumber($state),
                    'name' => $name,
                    'ranking' => array_values(array_filter(array_unique($ranking), fn ($p) => null !== self::findParticipant($state, $p))),
                    'captureKind' => self::isKnownKind($state, $kind) ? $kind : self::DEFAULT_KIND,
                ];
                $position = null === $before ? null : self::findWorkset($state, $before);
                array_splice($state['worksets'], $position ?? count($state['worksets']), 0, [$entry]);

                return $state;

            case 'workset.rename':
                $id = self::id($op['worksetId'] ?? null, 'workset_id');
                $name = self::optionalName($op['name'] ?? null, self::MAX_WORKSET_NAME, 'workset_name');
                $index = self::findWorkset($state, $id);
                if (null !== $index) {
                    $state['worksets'][$index]['name'] = $name;
                }

                return $state;

            case 'workset.delete':
                $id = self::id($op['worksetId'] ?? null, 'workset_id');
                // Captures keep the id; the next workset (if any) becomes the default.
                $state['worksets'] = array_values(array_filter($state['worksets'], static fn ($w) => $w['id'] !== $id));

                return $state;

            case 'workset.makeDefault':
                $id = self::id($op['worksetId'] ?? null, 'workset_id');
                $index = self::findWorkset($state, $id);
                if (null !== $index) {
                    $workset = array_splice($state['worksets'], $index, 1)[0];
                    array_unshift($state['worksets'], $workset);
                }

                return $state;

            case 'workset.setKind':
                $id = self::id($op['worksetId'] ?? null, 'workset_id');
                $kind = self::captureKind($op['kind'] ?? null);
                $index = self::findWorkset($state, $id);
                if (null !== $index && self::isKnownKind($state, $kind)) {
                    $state['worksets'][$index]['captureKind'] = $kind;
                }

                return $state;

            case 'workset.ranking.add':
                $index = self::findWorkset($state, self::id($op['worksetId'] ?? null, 'workset_id'));
                $id = self::id($op['participantId'] ?? null, 'participant_id');
                if (null !== $index && null !== self::findParticipant($state, $id) && !in_array($id, $state['worksets'][$index]['ranking'], true)) {
                    $state['worksets'][$index]['ranking'][] = $id;
                }

                return $state;

            case 'workset.ranking.remove':
                $index = self::findWorkset($state, self::id($op['worksetId'] ?? null, 'workset_id'));
                $id = self::id($op['participantId'] ?? null, 'participant_id');
                if (null !== $index) {
                    $state['worksets'][$index]['ranking'] = self::without($state['worksets'][$index]['ranking'], $id);
                }

                return $state;

            case 'workset.ranking.move':
                $index = self::findWorkset($state, self::id($op['worksetId'] ?? null, 'workset_id'));
                $id = self::id($op['participantId'] ?? null, 'participant_id');
                $before = self::optionalId($op['beforeId'] ?? null, 'before_id');
                if (null === $index || !in_array($id, $state['worksets'][$index]['ranking'], true)) {
                    return $state;
                }
                $ranking = self::without($state['worksets'][$index]['ranking'], $id);
                $position = (null === $before || $before === $id) ? false : array_search($before, $ranking, true);
                if (false === $position) {
                    $ranking[] = $id;
                } else {
                    array_splice($ranking, $position, 0, [$id]);
                }
                $state['worksets'][$index]['ranking'] = $ranking;

                return $state;

            case 'state.merge':
                return $this->merge($state, $op['state'] ?? null);

            default:
                throw new InvalidOperationException('unknown_type');
        }
    }

    /** Version 2: a capture's single `participantId` becomes its list of `targets`. */
    private static function toVersion2(array $state): array
    {
        foreach ($state['captures'] ?? [] as $i => $capture) {
            if (!is_array($capture)) {
                continue;
            }
            $participantId = $capture['participantId'] ?? null;
            $migrated = [];
            foreach ($capture as $key => $value) {
                if ('participantId' === $key) {
                    continue;
                }
                $migrated[$key] = $value;
                if ('tzOffset' === $key && !array_key_exists('targets', $capture)) {
                    $migrated['targets'] = is_string($participantId) ? [['type' => 'participant', 'id' => $participantId]] : [];
                }
            }
            $migrated += ['targets' => is_string($participantId) ? [['type' => 'participant', 'id' => $participantId]] : []];
            $state['captures'][$i] = $migrated;
        }

        return $state;
    }

    /** Applies the migration steps from $version up to SCHEMA_VERSION (see MIGRATIONS). */
    private static function runMigrations(array $state, int $version): array
    {
        for (; $version < self::SCHEMA_VERSION; ++$version) {
            $step = self::MIGRATIONS[$version];
            $state = self::$step($state);
        }

        return $state;
    }

    /**
     * Merges another state into this one without ever removing anything: participants are
     * matched by id or (case-insensitive) name, custom kinds and worksets likewise (worksets by
     * name only when both are named), rankings are merged per workset, captures are added
     * unless their id already exists.
     */
    private function merge(array $state, mixed $source): array
    {
        if (!is_array($source)) {
            throw new InvalidOperationException('invalid_state');
        }
        // A state of an earlier schema version is migrated first (no version = 1).
        $version = $source['schema'] ?? 1;
        if (!is_int($version) || $version < 1 || $version > self::SCHEMA_VERSION) {
            throw new InvalidOperationException('invalid_schema');
        }
        $source = self::runMigrations($source, $version);
        $participants = $source['participants'] ?? [];
        $kinds = $source['kinds'] ?? [];
        $worksets = $source['worksets'] ?? [];
        $captures = $source['captures'] ?? [];
        if (!is_array($participants) || !is_array($kinds) || !is_array($worksets) || !is_array($captures)
            || count($participants) > 5000 || count($kinds) > 500 || count($worksets) > 500 || count($captures) > 20000) {
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

        // Custom kinds are matched by id or (case-insensitive) name, like participants.
        $kindMap = [];
        foreach ($kinds as $kind) {
            $kind = self::customKind($kind);
            $index = self::findKind($state, $kind['id']) ?? self::findKindByName($state, $kind['name']);
            if (null === $index) {
                $state['kinds'][] = $kind;
                $kindMap[$kind['id']] = $kind['id'];
            } else {
                $kindMap[$kind['id']] = $state['kinds'][$index]['id'];
            }
        }

        // Worksets: a new one keeps its kind and gets the next number; an existing one keeps
        // its kind, and only its ranking is extended.
        $worksetMap = [];
        foreach ($worksets as $workset) {
            if (!is_array($workset)) {
                throw new InvalidOperationException('invalid_workset');
            }
            $id = self::id($workset['id'] ?? null, 'workset_id');
            $name = self::optionalName($workset['name'] ?? null, self::MAX_WORKSET_NAME, 'workset_name');
            $ranking = self::rankingList($workset['ranking'] ?? null);
            $kind = self::captureKind($workset['captureKind'] ?? null);
            $index = self::findWorkset($state, $id) ?? (null !== $name ? self::findWorksetByName($state, $name) : null);
            if (null === $index) {
                $kind = $kindMap[$kind] ?? $kind;
                $state['worksets'][] = [
                    'id' => $id, 'number' => self::nextWorksetNumber($state), 'name' => $name, 'ranking' => [],
                    'captureKind' => self::isKnownKind($state, $kind) ? $kind : self::DEFAULT_KIND,
                ];
                $index = count($state['worksets']) - 1;
            }
            $worksetMap[$id] = $state['worksets'][$index]['id'];
            foreach ($ranking as $participantId) {
                $mapped = $idMap[$participantId] ?? null;
                if (null !== $mapped && !in_array($mapped, $state['worksets'][$index]['ranking'], true)) {
                    $state['worksets'][$index]['ranking'][] = $mapped;
                }
            }
        }

        foreach ($captures as $capture) {
            if (!is_array($capture)) {
                throw new InvalidOperationException('invalid_capture');
            }
            $id = self::id($capture['id'] ?? null, 'capture_id');
            $ts = self::timestamp($capture['ts'] ?? null);
            $tzOffset = self::tzOffset($capture['tzOffset'] ?? null);
            $targets = self::targetList($capture['targets'] ?? []);
            $kind = self::captureKind($capture['kind'] ?? null);
            $worksetId = self::optionalId($capture['worksetId'] ?? null, 'workset_id');
            if (null !== self::findCapture($state, $id)) {
                continue;
            }
            // Participants are mapped to the ones they were matched with; unknown ones are dropped.
            $mapped = [];
            foreach ($targets as $target) {
                $ref = ['type' => 'participant', 'id' => $idMap[$target['id']] ?? null];
                if (null !== $ref['id'] && !in_array($ref, $mapped, true)) {
                    $mapped[] = $ref;
                }
            }
            $state['captures'][] = [
                'id' => $id, 'ts' => $ts, 'tzOffset' => $tzOffset,
                'targets' => $mapped,
                'kind' => $kindMap[$kind] ?? $kind,
                'worksetId' => null !== $worksetId ? ($worksetMap[$worksetId] ?? $worksetId) : null,
            ];
        }

        $name = $source['name'] ?? null;
        if (null === $state['name'] && is_string($name) && '' !== self::clean($name)) {
            $state['name'] = self::name($name, self::MAX_RACE_NAME, 'name');
        }

        $sport = $source['sport'] ?? null;
        if (null !== $sport) {
            if (!is_string($sport) || !in_array($sport, self::SPORTS, true)) {
                throw new InvalidOperationException('invalid_sport');
            }
            // Only fills in a sport the race hasn't chosen yet (it still has the default).
            if (self::DEFAULT_SPORT === $state['sport']) {
                $state['sport'] = $sport;
            }
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

    /**
     * The targets of a capture in capture.add: its `targets`, or the single `participantId` of
     * earlier versions when it has none.
     *
     * @return list<array{type: string, id: string}>
     */
    private static function captureTargets(array $capture): array
    {
        if (isset($capture['targets'])) {
            return self::targetList($capture['targets']);
        }
        $participantId = self::optionalId($capture['participantId'] ?? null, 'participant_id');

        return null === $participantId ? [] : [['type' => 'participant', 'id' => $participantId]];
    }

    /** @return list<array{type: string, id: string}> without duplicates, in their order */
    private static function targetList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_TARGETS) {
            throw new InvalidOperationException('invalid_capture_targets');
        }
        $targets = [];
        foreach ($value as $item) {
            $target = self::target($item);
            if (!in_array($target, $targets, true)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /** @return array{type: string, id: string} */
    private static function target(mixed $value): array
    {
        $type = is_array($value) ? ($value['type'] ?? null) : null;
        $id = is_array($value) ? ($value['id'] ?? null) : null;
        if (!in_array($type, self::TARGET_TYPES, true) || !is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
            throw new InvalidOperationException('invalid_capture_target');
        }

        return ['type' => $type, 'id' => $id];
    }

    /** The targets that exist in the state (lenient about references). */
    private static function existingTargets(array $state, array $targets): array
    {
        return array_values(array_filter($targets, static fn ($t) => null !== self::findParticipant($state, $t['id'])));
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

    /** An optional name: null or blank means none. */
    private static function optionalName(mixed $value, int $max, string $field): ?string
    {
        return (null === $value || (is_string($value) && '' === self::clean($value))) ? null : self::name($value, $max, $field);
    }

    /** A workset's number when given explicitly (restoring a deleted workset); null = next free one. */
    private static function worksetNumber(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if (!is_int($value) || $value < 1 || $value > self::MAX_WORKSET_NUMBER) {
            throw new InvalidOperationException('invalid_workset_number');
        }

        return $value;
    }

    /** @return list<string> participant ids; null means an empty ranking */
    private static function rankingList(mixed $value): array
    {
        if (null === $value) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 5000) {
            throw new InvalidOperationException('invalid_ranking');
        }

        return array_map(static fn ($id) => self::id($id, 'participant_id'), $value);
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

    /** The kind of a capture: a built-in or custom kind id; null (older clients) means finish. */
    private static function captureKind(mixed $value): string
    {
        if (null === $value) {
            return self::DEFAULT_KIND;
        }
        if (!is_string($value) || !preg_match(self::ID_PATTERN, $value)) {
            throw new InvalidOperationException('invalid_capture_kind');
        }

        return $value;
    }

    /** A custom kind definition {id, name, role}; its id must not be a built-in one. */
    private static function customKind(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidOperationException('invalid_kind');
        }
        $id = self::id($value['id'] ?? null, 'kind_id');
        if (in_array($id, self::BUILTIN_KINDS, true)) {
            throw new InvalidOperationException('invalid_kind_id');
        }
        $name = self::name($value['name'] ?? null, self::MAX_KIND_NAME, 'kind_name');
        $role = self::kindRoleValue($value['role'] ?? null);

        return ['id' => $id, 'name' => $name, 'role' => $role];
    }

    private static function kindRoleValue(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::CUSTOM_KIND_ROLES, true)) {
            throw new InvalidOperationException('invalid_kind_role');
        }

        return $value;
    }

    /** Role of a kind id: a built-in kind is its own role; unknown ids count as markers. */
    private static function kindRole(array $state, string $kind): string
    {
        if (in_array($kind, self::BUILTIN_KINDS, true)) {
            return $kind;
        }
        $index = self::findKind($state, $kind);

        return null === $index ? 'marker' : $state['kinds'][$index]['role'];
    }

    private static function isKnownKind(array $state, string $kind): bool
    {
        return in_array($kind, self::BUILTIN_KINDS, true) || null !== self::findKind($state, $kind);
    }

    private static function findKind(array $state, string $id): ?int
    {
        foreach ($state['kinds'] as $i => $kind) {
            if ($kind['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private static function findKindByName(array $state, string $name): ?int
    {
        $key = self::key($name);
        foreach ($state['kinds'] as $i => $kind) {
            if (self::key($kind['name']) === $key) {
                return $i;
            }
        }

        return null;
    }

    private static function findWorkset(array $state, string $id): ?int
    {
        foreach ($state['worksets'] as $i => $workset) {
            if ($workset['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private static function findWorksetByName(array $state, string $name): ?int
    {
        $key = self::key($name);
        foreach ($state['worksets'] as $i => $workset) {
            if (null !== $workset['name'] && self::key($workset['name']) === $key) {
                return $i;
            }
        }

        return null;
    }

    private static function nextWorksetNumber(array $state): int
    {
        return 1 + max([0, ...array_map(static fn ($w) => $w['number'], $state['worksets'])]);
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

<?php

namespace App\Regatta;

/**
 * Applies client operations to a regatta state. This is the authoritative
 * implementation; the frontend contains a line-by-line JavaScript mirror
 * (applyOp in frontend/index.html) used for optimistic updates. Keep both in sync.
 *
 * State shape:
 *   name: ?string, archived: bool,
 *   boats: list<{id, name}>, ranking: list<boatId>, captures: list<{id, ts, boatId: ?string}>
 *
 * Operations are lenient about references (e.g. assigning a capture to a boat that
 * was deleted concurrently is a no-op) so that buffered offline operations can
 * always be replayed, but strict about their shape.
 */
final class OperationReducer
{
    public const TYPES = [
        'regatta.rename', 'regatta.archive',
        'boats.add', 'boat.rename', 'boat.delete',
        'ranking.add', 'ranking.remove', 'ranking.move',
        'capture.add', 'capture.assign', 'capture.delete',
        'state.merge',
    ];

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';
    private const MAX_BOAT_NAME = 60;
    private const MAX_REGATTA_NAME = 80;

    public static function emptyState(): array
    {
        return ['name' => null, 'archived' => false, 'boats' => [], 'ranking' => [], 'captures' => []];
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
            case 'regatta.rename':
                $name = $op['name'] ?? null;
                $state['name'] = (null === $name || (is_string($name) && '' === self::clean($name)))
                    ? null
                    : self::name($name, self::MAX_REGATTA_NAME, 'name');

                return $state;

            case 'regatta.archive':
                $state['archived'] = true;

                return $state;

            case 'boats.add':
                $boats = $op['boats'] ?? null;
                if (!is_array($boats) || !array_is_list($boats) || count($boats) > 2000) {
                    throw new InvalidOperationException('invalid_boats');
                }
                foreach ($boats as $boat) {
                    $id = self::id(is_array($boat) ? ($boat['id'] ?? null) : null, 'boat_id');
                    $name = self::name(is_array($boat) ? ($boat['name'] ?? null) : null, self::MAX_BOAT_NAME, 'boat_name');
                    if (null !== self::findBoat($state, $id) || null !== self::findBoatByName($state, $name)) {
                        continue;
                    }
                    $state['boats'][] = ['id' => $id, 'name' => $name];
                }

                return $state;

            case 'boat.rename':
                $id = self::id($op['boatId'] ?? null, 'boat_id');
                $name = self::name($op['name'] ?? null, self::MAX_BOAT_NAME, 'boat_name');
                $index = self::findBoat($state, $id);
                if (null !== $index) {
                    $state['boats'][$index]['name'] = $name;
                }

                return $state;

            case 'boat.delete':
                $id = self::id($op['boatId'] ?? null, 'boat_id');
                $state['boats'] = array_values(array_filter($state['boats'], static fn ($b) => $b['id'] !== $id));
                $state['ranking'] = self::without($state['ranking'], $id);

                return $state;

            case 'ranking.add':
                $id = self::id($op['boatId'] ?? null, 'boat_id');
                if (null !== self::findBoat($state, $id) && !in_array($id, $state['ranking'], true)) {
                    $state['ranking'][] = $id;
                }

                return $state;

            case 'ranking.remove':
                $id = self::id($op['boatId'] ?? null, 'boat_id');
                $state['ranking'] = self::without($state['ranking'], $id);

                return $state;

            case 'ranking.move':
                $id = self::id($op['boatId'] ?? null, 'boat_id');
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
                $boatId = self::optionalId($capture['boatId'] ?? null, 'boat_id');
                if (null !== self::findCapture($state, $id)) {
                    return $state;
                }
                if (null !== $boatId && null === self::findBoat($state, $boatId)) {
                    $boatId = null;
                }
                $state['captures'][] = ['id' => $id, 'ts' => $ts, 'boatId' => $boatId];
                if (null !== $boatId) {
                    $state['ranking'] = self::without($state['ranking'], $boatId);
                }

                return $state;

            case 'capture.assign':
                $captureId = self::id($op['captureId'] ?? null, 'capture_id');
                $boatId = self::optionalId($op['boatId'] ?? null, 'boat_id');
                $index = self::findCapture($state, $captureId);
                if (null === $index || (null !== $boatId && null === self::findBoat($state, $boatId))) {
                    return $state;
                }
                $state['captures'][$index]['boatId'] = $boatId;

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
     * Merges another state into this one without ever removing anything: boats are
     * matched by id or (case-insensitive) name, missing boats are appended to the
     * ranking, captures are added unless their id already exists.
     */
    private function merge(array $state, mixed $source): array
    {
        if (!is_array($source)) {
            throw new InvalidOperationException('invalid_state');
        }
        $boats = $source['boats'] ?? [];
        $ranking = $source['ranking'] ?? [];
        $captures = $source['captures'] ?? [];
        if (!is_array($boats) || !is_array($ranking) || !is_array($captures)
            || count($boats) > 5000 || count($ranking) > 5000 || count($captures) > 20000) {
            throw new InvalidOperationException('invalid_state');
        }

        $idMap = [];
        foreach ($boats as $boat) {
            $id = self::id(is_array($boat) ? ($boat['id'] ?? null) : null, 'boat_id');
            $name = self::name(is_array($boat) ? ($boat['name'] ?? null) : null, self::MAX_BOAT_NAME, 'boat_name');
            $index = self::findBoat($state, $id) ?? self::findBoatByName($state, $name);
            if (null === $index) {
                $state['boats'][] = ['id' => $id, 'name' => $name];
                $idMap[$id] = $id;
            } else {
                $idMap[$id] = $state['boats'][$index]['id'];
            }
        }

        foreach ($ranking as $boatId) {
            $boatId = self::id($boatId, 'boat_id');
            $mapped = $idMap[$boatId] ?? null;
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
            $boatId = self::optionalId($capture['boatId'] ?? null, 'boat_id');
            if (null !== self::findCapture($state, $id)) {
                continue;
            }
            $state['captures'][] = ['id' => $id, 'ts' => $ts, 'boatId' => null !== $boatId ? ($idMap[$boatId] ?? null) : null];
        }

        $name = $source['name'] ?? null;
        if (null === $state['name'] && is_string($name) && '' !== self::clean($name)) {
            $state['name'] = self::name($name, self::MAX_REGATTA_NAME, 'name');
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

    private static function findBoat(array $state, string $id): ?int
    {
        foreach ($state['boats'] as $i => $boat) {
            if ($boat['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private static function findBoatByName(array $state, string $name): ?int
    {
        $key = self::key($name);
        foreach ($state['boats'] as $i => $boat) {
            if (self::key($boat['name']) === $key) {
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

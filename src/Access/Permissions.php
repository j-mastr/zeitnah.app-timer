<?php

namespace App\Access;

/**
 * Permission rules of access codes. Mirrored line by line in frontend/index.html (the
 * "Access rules" section); tests/access-parity.mjs keeps both identical.
 *
 * A permission is a path of segments, each a name with an optional entity id:
 * `race.rename`, `participant.add`, `workset[w1].ranking.add`, `workset[w1].capture.assign`.
 * Every operation maps to one (operationPath); viewing uses `<entity>.view` paths.
 *
 * A rule grants a path pattern; `revoke:` in front takes it away again, and a revoke always
 * wins over grants:
 *   rule    := ['revoke:'] path
 *   path    := segment ('.' segment)*
 *   segment := '*' | name ['[' id ']']
 * A segment without an id matches every id (and none), `[id]` exactly that one. `*` matches
 * one segment, or — as the last segment — everything that follows (at least one segment), so
 * `*` alone matches every path and `workset[w1].*` everything about workset w1. Entity ids in
 * rules leave room for containers above the race later (`race[id].archive`); today a code
 * targets one race and race paths carry no id. Rules that can't be parsed never match.
 */
final class Permissions
{
    /** The rules of a race code: everything. */
    public const FULL = ['*'];

    private const NAME = '[A-Za-z]+';
    private const ID = '[A-Za-z0-9_-]{1,40}';

    /** @var array<string, list<array{0: string, 1: ?string}>|null> */
    private static array $parsed = [];

    /**
     * Rules of the code created together with a workset: work on that workset (its kind, its
     * ranking, its captures) and see the race, but no other workset and nothing to manage.
     * Captures of other worksets (and those without one) are listed, not editable.
     *
     * @return list<string>
     */
    public static function worksetRules(string $worksetId): array
    {
        return [
            'race.view', 'participant.view', 'kind.view', 'capture.view', 'workset.capture.view',
            "workset[$worksetId].view", "workset[$worksetId].setKind", "workset[$worksetId].ranking.*", "workset[$worksetId].capture.*",
        ];
    }

    /** @return list<array{0: string, 1: ?string}>|null */
    public static function parse(string $path): ?array
    {
        if (array_key_exists($path, self::$parsed)) {
            return self::$parsed[$path];
        }
        $segments = [];
        foreach (explode('.', $path) as $segment) {
            if ('*' === $segment) {
                $segments[] = ['*', null];
            } elseif (preg_match('/^('.self::NAME.')(?:\[('.self::ID.')\])?$/', $segment, $m)) {
                $segments[] = [$m[1], $m[2] ?? null];
            } else {
                return self::$parsed[$path] = null;
            }
        }

        return self::$parsed[$path] = $segments;
    }

    /**
     * @param list<array{0: string, 1: ?string}> $rule
     * @param list<array{0: string, 1: ?string}> $path
     */
    public static function matches(array $rule, array $path): bool
    {
        $last = count($rule) - 1;
        foreach ($rule as $i => [$name, $id]) {
            if (!isset($path[$i])) {
                return false;
            }
            if ('*' === $name) {
                if ($i === $last) {
                    return true;
                }
                continue;
            }
            if ($path[$i][0] !== $name || (null !== $id && $path[$i][1] !== $id)) {
                return false;
            }
        }

        return count($rule) === count($path);
    }

    /**
     * @param list<mixed>                            $rules
     * @param list<array{0: string, 1: ?string}> $path
     */
    public static function allows(array $rules, array $path): bool
    {
        $granted = false;
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }
            $revoke = str_starts_with($rule, 'revoke:');
            $parsed = self::parse($revoke ? substr($rule, 7) : $rule);
            if (null === $parsed || !self::matches($parsed, $path)) {
                continue;
            }
            if ($revoke) {
                return false;
            }
            $granted = true;
        }

        return $granted;
    }

    /**
     * Whether the rules only reach particular worksets: some grant names a workset by id, and
     * an arbitrary other workset is out of sight. Such a code is revoked once none of its
     * worksets exists any more (see Access::isRevokedIn).
     *
     * @param list<mixed> $rules
     */
    public static function restrictedToWorksets(array $rules): bool
    {
        // '' is never a real id: only rules without an id (all worksets) match it.
        if (self::allows($rules, [['workset', ''], ['view', null]])) {
            return false;
        }
        foreach ($rules as $rule) {
            if (is_string($rule) && !str_starts_with($rule, 'revoke:')) {
                $parsed = self::parse($rule);
                if (null !== $parsed && 'workset' === $parsed[0][0] && null !== $parsed[0][1]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The permission an operation needs, or null when it needs none (it refers to a capture
     * that doesn't exist, so it changes nothing).
     *
     * @return list<array{0: string, 1: ?string}>|null
     */
    public static function operationPath(array $state, array $op): ?array
    {
        $type = is_string($op['type'] ?? null) ? $op['type'] : '';
        $parts = explode('.', $type);
        switch ($type) {
            case 'race.rename': case 'race.setSport': case 'race.archive':
            case 'participant.rename': case 'participant.delete':
            case 'kind.add': case 'kind.update': case 'kind.delete':
            case 'workset.add':
                return [[$parts[0], null], [$parts[1], null]];
            case 'participants.add':
                return [['participant', null], ['add', null]];
            case 'state.merge':
                return [['race', null], ['merge', null]];
            case 'workset.rename': case 'workset.delete': case 'workset.makeDefault': case 'workset.setKind':
            case 'workset.ranking.add': case 'workset.ranking.remove': case 'workset.ranking.move':
                return [self::worksetSegment($op['worksetId'] ?? null), ...array_map(static fn ($p) => [$p, null], array_slice($parts, 1))];
            case 'capture.add':
                $capture = is_array($op['capture'] ?? null) ? $op['capture'] : [];

                return [...self::captureScope($capture['worksetId'] ?? null), ['add', null]];
            case 'capture.assign': case 'capture.delete': case 'capture.setKind':
                foreach ($state['captures'] ?? [] as $capture) {
                    if ($capture['id'] === ($op['captureId'] ?? null)) {
                        return [...self::captureScope($capture['worksetId'] ?? null), [$parts[1], null]];
                    }
                }

                return null;
            default:
                return null;
        }
    }

    /**
     * Path prefix of a capture: the capture of a workset (`workset[w1].capture`) or, without
     * one, a plain `capture`.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public static function captureScope(mixed $worksetId): array
    {
        return null === $worksetId ? [['capture', null]] : [self::worksetSegment($worksetId), ['capture', null]];
    }

    /** @return array{0: string, 1: string} */
    private static function worksetSegment(mixed $id): array
    {
        // An invalid id matches no rule naming a workset; the reducer rejects it anyway.
        return ['workset', is_string($id) ? $id : ''];
    }
}

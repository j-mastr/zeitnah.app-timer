<?php

namespace App\Access;

use App\Race\Database;
use App\Race\RaceNotFoundException;

/**
 * Access codes: the codes clients connect with. Each targets a race (later possibly other
 * containers, see `target_type`) and carries rules (see Permissions). The race code grants
 * everything; every workset gets a code of its own when it is created (`source` 'workset',
 * `source_ref` = workset id). `source` records where a code came from; which worksets a code
 * reaches is only ever read from its rules.
 *
 * All codes share one namespace: 6 characters from an alphabet without look-alikes,
 * case-insensitive, other characters ignored.
 */
class AccessCodeRepository
{
    public const TARGET_RACE = 'race';
    public const SOURCE_RACE = 'race';
    public const SOURCE_WORKSET = 'workset';
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 6;

    public function __construct(private readonly Database $db)
    {
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    public static function generateCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    /** @throws RaceNotFoundException when no race code or access code matches */
    public function resolve(string $code): Access
    {
        $stmt = $this->db->pdo()->prepare('SELECT code, target_id, rules, revoked_at FROM access_code WHERE code = ? AND target_type = ?');
        $stmt->execute([self::normalizeCode($code), self::TARGET_RACE]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RaceNotFoundException($code);
        }

        return new Access((int) $row['target_id'], $row['code'], self::decodeRules($row['rules']), null !== $row['revoked_at']);
    }

    /** Whether a code is taken (by any target). */
    public function exists(string $code): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM access_code WHERE code = ?');
        $stmt->execute([$code]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Inserts a code. Runs inside the caller's transaction; a taken code surfaces as a unique
     * violation (PDOException) for the caller to retry.
     *
     * @param list<string> $rules
     */
    public function insert(int $raceId, string $code, array $rules, string $source, ?string $sourceRef, string $now): void
    {
        $this->db->pdo()
            ->prepare('INSERT INTO access_code (code, target_type, target_id, rules, source, source_ref, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$code, self::TARGET_RACE, $raceId, json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $source, $sourceRef, $now]);
    }

    /**
     * Gives every workset of the race that has none its own code. Must run inside a
     * transaction holding the race row, so two writers can't create a code for the same
     * workset. A workset that comes back (undo of its deletion) finds its old code.
     */
    public function ensureWorksetCodes(int $raceId, array $state, string $now): void
    {
        if (!$state['worksets']) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('SELECT source_ref FROM access_code WHERE target_type = ? AND target_id = ? AND source = ?');
        $stmt->execute([self::TARGET_RACE, $raceId, self::SOURCE_WORKSET]);
        $known = array_flip(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
        foreach ($state['worksets'] as $workset) {
            if (isset($known[$workset['id']])) {
                continue;
            }
            // Checked first rather than caught: a failed statement would abort a PostgreSQL transaction.
            do {
                $code = self::generateCode();
            } while ($this->exists($code));
            $this->insert($raceId, $code, Permissions::worksetRules($workset['id']), self::SOURCE_WORKSET, $workset['id'], $now);
        }
    }

    /**
     * The race's codes that aren't revoked, for clients allowed to see them.
     *
     * @return list<array{code: string, rules: list<string>, source: string, sourceRef: ?string}>
     */
    public function codesOf(int $raceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, rules, source, source_ref FROM access_code WHERE target_type = ? AND target_id = ? AND revoked_at IS NULL ORDER BY id'
        );
        $stmt->execute([self::TARGET_RACE, $raceId]);

        return array_map(static fn (array $row) => [
            'code' => $row['code'], 'rules' => self::decodeRules($row['rules']), 'source' => $row['source'], 'sourceRef' => $row['source_ref'],
        ], $stmt->fetchAll());
    }

    /** @return list<string> */
    private static function decodeRules(string $json): array
    {
        $rules = json_decode($json, true);

        return is_array($rules) ? array_values(array_filter($rules, 'is_string')) : [];
    }
}

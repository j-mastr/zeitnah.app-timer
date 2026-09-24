<?php

namespace App\Regatta;

/**
 * Persists regattas as a materialized state plus an append-only, gap-free event log.
 *
 * Every accepted operation gets the next sequence number of its regatta. Clients
 * track the last sequence number they have seen and fetch (or are pushed) the
 * events after it. Operation ids are unique per regatta, which makes resending
 * buffered operations after a reconnect idempotent.
 */
class RegattaRepository
{
    public const MAX_OPS_PER_BATCH = 200;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 6;
    private const OP_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly Database $db,
        private readonly OperationReducer $reducer,
    ) {
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** @return array{code: string, seq: int, state: array} */
    public function create(): array
    {
        $state = OperationReducer::emptyState();
        $now = self::now();
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            try {
                $this->db->pdo()
                    ->prepare('INSERT INTO regatta (code, seq, state, created_at, updated_at) VALUES (?, 0, ?, ?, ?)')
                    ->execute([$code, self::encode($state), $now, $now]);

                return ['code' => $code, 'seq' => 0, 'state' => $state];
            } catch (\PDOException $e) {
                if (!self::isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not generate a unique regatta code.');
    }

    /** @return array{code: string, seq: int, state: array} */
    public function snapshot(string $code): array
    {
        $row = $this->findRow($code);

        return ['code' => $row['code'], 'seq' => (int) $row['seq'], 'state' => self::decode($row['state'])];
    }

    /**
     * Events after $since, oldest first.
     *
     * @return list<array{seq: int, op: array}>
     */
    public function eventsSince(string $code, int $since, int $limit = 1000): array
    {
        $row = $this->findRow($code);
        $stmt = $this->db->pdo()->prepare(
            'SELECT seq, op FROM regatta_event WHERE regatta_id = ? AND seq > ? ORDER BY seq ASC LIMIT '.max(1, $limit)
        );
        $stmt->execute([(int) $row['id'], $since]);

        return array_map(
            static fn (array $e) => ['seq' => (int) $e['seq'], 'op' => self::decode($e['op'])],
            $stmt->fetchAll()
        );
    }

    /**
     * Current sequence numbers of the given regattas (missing codes are omitted).
     *
     * @param list<string> $codes
     *
     * @return array<string, int>
     */
    public function currentSeqs(array $codes): array
    {
        if (!$codes) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, seq FROM regatta WHERE code IN ('.implode(',', array_fill(0, count($codes), '?')).')'
        );
        $stmt->execute(array_values($codes));
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['code']] = (int) $row['seq'];
        }

        return $result;
    }

    /**
     * Applies operations in order. Each result is one of
     *   ['opId' => ..., 'status' => 'applied', 'seq' => n]
     *   ['opId' => ..., 'status' => 'duplicate']            (already applied earlier)
     *   ['opId' => ..., 'status' => 'rejected', 'error' => code]
     *
     * @param list<mixed> $ops
     *
     * @return list<array<string, mixed>>
     */
    public function applyOperations(string $code, array $ops): array
    {
        $results = [];
        foreach (array_slice($ops, 0, self::MAX_OPS_PER_BATCH) as $op) {
            $results[] = $this->applyOperation($code, $op);
        }

        return $results;
    }

    private function applyOperation(string $code, mixed $op): array
    {
        $opId = is_array($op) ? ($op['opId'] ?? null) : null;
        if (!is_string($opId) || !preg_match(self::OP_ID_PATTERN, $opId)) {
            return ['opId' => is_string($opId) ? $opId : null, 'status' => 'rejected', 'error' => 'invalid_op_id'];
        }
        if (!in_array($op['type'] ?? null, OperationReducer::TYPES, true)) {
            return ['opId' => $opId, 'status' => 'rejected', 'error' => 'unknown_type'];
        }

        $pdo = $this->db->pdo();
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $this->db->begin();
            try {
                $stmt = $pdo->prepare('SELECT id, seq, state FROM regatta WHERE code = ?'.$this->db->forUpdate());
                $stmt->execute([self::normalizeCode($code)]);
                $row = $stmt->fetch();
                if (!$row) {
                    $this->db->rollback();
                    throw new RegattaNotFoundException($code);
                }
                $regattaId = (int) $row['id'];
                $seq = (int) $row['seq'];

                $dup = $pdo->prepare('SELECT 1 FROM regatta_event WHERE regatta_id = ? AND op_id = ?');
                $dup->execute([$regattaId, $opId]);
                if ($dup->fetchColumn()) {
                    $this->db->rollback();

                    return ['opId' => $opId, 'status' => 'duplicate'];
                }

                $state = self::decode($row['state']);
                if ($state['archived'] ?? false) {
                    $this->db->rollback();

                    return ['opId' => $opId, 'status' => 'rejected', 'error' => 'archived'];
                }

                try {
                    $state = $this->reducer->apply($state, $op);
                } catch (InvalidOperationException $e) {
                    $this->db->rollback();

                    return ['opId' => $opId, 'status' => 'rejected', 'error' => $e->getMessage()];
                }

                $now = self::now();
                $pdo->prepare('INSERT INTO regatta_event (regatta_id, seq, op_id, op, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$regattaId, $seq + 1, $opId, self::encode($op), $now]);
                $update = $pdo->prepare('UPDATE regatta SET seq = ?, state = ?, updated_at = ? WHERE id = ? AND seq = ?');
                $update->execute([$seq + 1, self::encode($state), $now, $regattaId, $seq]);
                if (1 !== $update->rowCount()) {
                    throw new ConcurrentModificationException();
                }
                $this->db->commit();

                return ['opId' => $opId, 'status' => 'applied', 'seq' => $seq + 1];
            } catch (RegattaNotFoundException $e) {
                throw $e;
            } catch (\PDOException|ConcurrentModificationException) {
                // Lost a race against another writer (or the database was busy): retry.
                $this->db->rollback();
                usleep(random_int(5_000, 40_000) * ($attempt + 1));
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        }

        return ['opId' => $opId, 'status' => 'rejected', 'error' => 'busy'];
    }

    private function findRow(string $code): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, code, seq, state FROM regatta WHERE code = ?');
        $stmt->execute([self::normalizeCode($code)]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RegattaNotFoundException($code);
        }

        return $row;
    }

    private static function isUniqueViolation(\PDOException $e): bool
    {
        // SQLSTATE 23000 (MySQL/SQLite) / 23505 (PostgreSQL)
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}

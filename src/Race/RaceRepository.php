<?php

namespace App\Race;

use App\Access\Access;
use App\Access\AccessCodeRepository;
use App\Access\Permissions;

/**
 * Persists races as a materialized state plus an append-only, gap-free event log.
 *
 * Every accepted operation gets the next sequence number of its race. Clients
 * track the last sequence number they have seen and fetch (or are pushed) the
 * events after it. Operation ids are unique per race, which makes resending
 * buffered operations after a reconnect idempotent.
 *
 * Clients reach a race through an access code (see AccessCodeRepository): its rules decide
 * which operations are accepted and what of the state and the events the client gets to see.
 */
class RaceRepository
{
    public const MAX_OPS_PER_BATCH = 200;
    private const OP_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
    /** Operations that can create worksets, which then get codes of their own. */
    private const WORKSET_CREATING_OPS = ['workset.add', 'state.merge'];

    public function __construct(
        private readonly Database $db,
        private readonly OperationReducer $reducer,
        private readonly AccessCodeRepository $codes,
    ) {
    }

    public static function normalizeCode(string $code): string
    {
        return AccessCodeRepository::normalizeCode($code);
    }

    /**
     * Creates a race together with its race code (full access).
     *
     * @return array{code: string, seq: int, state: array}
     */
    public function create(): array
    {
        $state = OperationReducer::emptyState();
        $now = self::now();
        $pdo = $this->db->pdo();
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $code = AccessCodeRepository::generateCode();
            if ($this->codes->exists($code)) {
                continue;
            }
            $this->db->begin();
            try {
                $pdo->prepare('INSERT INTO race (code, seq, state, created_at, updated_at) VALUES (?, 0, ?, ?, ?)')
                    ->execute([$code, self::encode($state), $now, $now]);
                $raceId = (int) $pdo->lastInsertId('pgsql' === $this->db->driver() ? 'race_id_seq' : null);
                $this->codes->insert($raceId, $code, Permissions::FULL, AccessCodeRepository::SOURCE_RACE, null, $now);
                $this->db->commit();

                return ['code' => $code, 'seq' => 0, 'state' => $state];
            } catch (\PDOException $e) {
                $this->db->rollback();
                if (!self::isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not generate a unique race code.');
    }

    /** @throws RaceNotFoundException */
    public function resolve(string $code): Access
    {
        return $this->codes->resolve($code);
    }

    /**
     * The race as the code's clients see it.
     *
     * @return array{code: string, seq: int, state: array, access: array}
     *
     * @throws RaceNotFoundException|AccessRevokedException
     */
    public function snapshot(Access $access): array
    {
        [$seq, $state] = $this->load($access);

        return $this->snapshotOf($access, $seq, $state);
    }

    /**
     * Events after $since as the code's clients see them, or a fresh snapshot (`reset`)
     * when the client is more than $maxGap events behind.
     *
     * @throws RaceNotFoundException|AccessRevokedException
     */
    public function poll(Access $access, int $since, int $maxGap): array
    {
        [$seq, $state] = $this->load($access);
        if ($seq - $since > $maxGap || $since > $seq) {
            return ['reset' => true] + $this->snapshotOf($access, $seq, $state);
        }

        return [
            'seq' => $seq,
            'events' => array_map($access->filterEvent(...), $this->eventsSince($access->raceId, $since, $maxGap)),
            'access' => $this->accessInfo($access, $state),
        ];
    }

    /**
     * What a client learns about its access: the code to reconnect with, its rules and — when
     * it may see them (`access.view`) — the race's codes, e.g. to share a workset's code.
     *
     * @return array{code: string, rules: list<string>, codes?: list<array>}
     */
    public function accessInfo(Access $access, array $state): array
    {
        $info = ['code' => $access->code, 'rules' => $access->rules];
        if (!$access->allows([['access', null], ['view', null]])) {
            return $info;
        }
        $codes = $this->codes->codesOf($access->raceId);
        $known = array_column($codes, 'sourceRef');
        foreach ($state['worksets'] as $workset) {
            if (!in_array($workset['id'], $known, true)) {
                // Worksets from before access codes existed get theirs now.
                $this->ensureWorksetCodes($access->raceId);
                $codes = $this->codes->codesOf($access->raceId);
                break;
            }
        }

        return $info + ['codes' => $codes];
    }

    /**
     * Current state of a race (for re-checking the access of connected clients).
     *
     * @throws RaceNotFoundException
     */
    public function state(int $raceId): array
    {
        return OperationReducer::upgrade(self::decode($this->findRow($raceId)['state']));
    }

    /**
     * Events after $since, oldest first, unfiltered.
     *
     * @return list<array{seq: int, op: array}>
     */
    public function eventsSince(int $raceId, int $since, int $limit = 1000): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT seq, op FROM race_event WHERE race_id = ? AND seq > ? ORDER BY seq ASC LIMIT '.max(1, $limit)
        );
        $stmt->execute([$raceId, $since]);

        return array_map(
            static fn (array $e) => ['seq' => (int) $e['seq'], 'op' => self::decode($e['op'])],
            $stmt->fetchAll()
        );
    }

    /**
     * Current sequence numbers of the given races (missing races are omitted).
     *
     * @param list<int> $raceIds
     *
     * @return array<int, int>
     */
    public function currentSeqs(array $raceIds): array
    {
        if (!$raceIds) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, seq FROM race WHERE id IN ('.implode(',', array_fill(0, count($raceIds), '?')).')'
        );
        $stmt->execute(array_values($raceIds));
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['id']] = (int) $row['seq'];
        }

        return $result;
    }

    /**
     * Applies operations in order, as far as the access code allows them. Each result is one of
     *   ['opId' => ..., 'status' => 'applied', 'seq' => n]
     *   ['opId' => ..., 'status' => 'duplicate']            (already applied earlier)
     *   ['opId' => ..., 'status' => 'rejected', 'error' => code]   (e.g. forbidden, access_revoked)
     *
     * @param list<mixed> $ops
     *
     * @return list<array<string, mixed>>
     */
    public function applyOperations(Access $access, array $ops): array
    {
        $results = [];
        foreach (array_slice($ops, 0, self::MAX_OPS_PER_BATCH) as $op) {
            $results[] = $this->applyOperation($access, $op);
        }

        return $results;
    }

    private function applyOperation(Access $access, mixed $op): array
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
                $stmt = $pdo->prepare('SELECT id, seq, state FROM race WHERE id = ?'.$this->db->forUpdate());
                $stmt->execute([$access->raceId]);
                $row = $stmt->fetch();
                if (!$row) {
                    $this->db->rollback();
                    throw new RaceNotFoundException($access->code);
                }
                $raceId = (int) $row['id'];
                $seq = (int) $row['seq'];

                $dup = $pdo->prepare('SELECT 1 FROM race_event WHERE race_id = ? AND op_id = ?');
                $dup->execute([$raceId, $opId]);
                if ($dup->fetchColumn()) {
                    $this->db->rollback();

                    return ['opId' => $opId, 'status' => 'duplicate'];
                }

                $state = OperationReducer::upgrade(self::decode($row['state']));
                $error = match (true) {
                    $state['archived'] ?? false => 'archived',
                    $access->isRevokedIn($state) => 'access_revoked',
                    default => $access->checkOperation($state, $op),
                };
                if (null !== $error) {
                    $this->db->rollback();

                    return ['opId' => $opId, 'status' => 'rejected', 'error' => $error];
                }

                try {
                    $state = $this->reducer->apply($state, $op);
                } catch (InvalidOperationException $e) {
                    $this->db->rollback();

                    return ['opId' => $opId, 'status' => 'rejected', 'error' => $e->getMessage()];
                }

                $now = self::now();
                $pdo->prepare('INSERT INTO race_event (race_id, seq, op_id, op, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$raceId, $seq + 1, $opId, self::encode($op), $now]);
                $update = $pdo->prepare('UPDATE race SET seq = ?, state = ?, updated_at = ? WHERE id = ? AND seq = ?');
                $update->execute([$seq + 1, self::encode($state), $now, $raceId, $seq]);
                if (1 !== $update->rowCount()) {
                    throw new ConcurrentModificationException();
                }
                if (in_array($op['type'], self::WORKSET_CREATING_OPS, true)) {
                    $this->codes->ensureWorksetCodes($raceId, $state, $now);
                }
                $this->db->commit();

                return ['opId' => $opId, 'status' => 'applied', 'seq' => $seq + 1];
            } catch (RaceNotFoundException $e) {
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

    /**
     * Sequence number and state of the code's race.
     *
     * @return array{0: int, 1: array}
     *
     * @throws RaceNotFoundException|AccessRevokedException
     */
    private function load(Access $access): array
    {
        $row = $this->findRow($access->raceId);
        $state = OperationReducer::upgrade(self::decode($row['state']));
        if ($access->isRevokedIn($state)) {
            throw new AccessRevokedException($access->code);
        }

        return [(int) $row['seq'], $state];
    }

    private function snapshotOf(Access $access, int $seq, array $state): array
    {
        return [
            'code' => $access->code, 'schema' => OperationReducer::SCHEMA_VERSION, 'seq' => $seq,
            'state' => $access->project($state), 'access' => $this->accessInfo($access, $state),
        ];
    }

    /** Creates the missing workset codes of a race, holding its row like a write does. */
    private function ensureWorksetCodes(int $raceId): void
    {
        $pdo = $this->db->pdo();
        $this->db->begin();
        try {
            $stmt = $pdo->prepare('SELECT state FROM race WHERE id = ?'.$this->db->forUpdate());
            $stmt->execute([$raceId]);
            $row = $stmt->fetch();
            if ($row) {
                $this->codes->ensureWorksetCodes($raceId, OperationReducer::upgrade(self::decode($row['state'])), self::now());
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function findRow(int $raceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, code, seq, state FROM race WHERE id = ?');
        $stmt->execute([$raceId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RaceNotFoundException((string) $raceId);
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

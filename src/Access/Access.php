<?php

namespace App\Access;

/**
 * What one access code grants: its race, its rules (see Permissions) and whether it was
 * revoked. Besides checking operations it decides what the code's clients get to see: the
 * state is projected (worksets and captures out of sight removed) and events on them are
 * redacted, so a client never receives data it may not view.
 */
final class Access
{
    /**
     * @param list<string> $rules
     */
    public function __construct(
        public readonly int $raceId,
        public readonly string $code,
        public readonly array $rules,
        public readonly bool $revoked = false,
    ) {
    }

    public function isFull(): bool
    {
        return Permissions::FULL === $this->rules;
    }

    /** @param list<array{0: string, 1: ?string}> $path */
    public function allows(array $path): bool
    {
        return Permissions::allows($this->rules, $path);
    }

    /** Null when the operation is allowed, else the error code to reject it with. */
    public function checkOperation(array $state, array $op): ?string
    {
        $path = Permissions::operationPath($state, $op);

        return null === $path || $this->allows($path) ? null : 'forbidden';
    }

    /**
     * Revoked explicitly, or restricted to particular worksets none of which exists any more.
     * The rules stay as they are: if such a workset comes back (undo), so does the access.
     */
    public function isRevokedIn(array $state): bool
    {
        if ($this->revoked) {
            return true;
        }
        if (!Permissions::restrictedToWorksets($this->rules)) {
            return false;
        }
        foreach ($state['worksets'] as $workset) {
            if ($this->canViewWorkset($workset['id'])) {
                return false;
            }
        }

        return true;
    }

    public function canViewWorkset(string $worksetId): bool
    {
        return $this->allows([['workset', $worksetId], ['view', null]]);
    }

    public function canViewCapture(mixed $worksetId): bool
    {
        return $this->allows([...Permissions::captureScope($worksetId), ['view', null]]);
    }

    /** The state as this code's clients see it. */
    public function project(array $state): array
    {
        if ($this->isFull()) {
            return $state;
        }
        if (!$this->allows([['participant', null], ['view', null]])) {
            // Groups are participant data: seen with the participants.
            $state['participants'] = [];
            $state['groupTypes'] = [];
            $state['groups'] = [];
            $state['fields'] = [];
        }
        if (!$this->allows([['kind', null], ['view', null]])) {
            $state['kinds'] = [];
        }
        $state['worksets'] = array_values(array_filter($state['worksets'], fn ($w) => $this->canViewWorkset($w['id'])));
        $state['captures'] = array_values(array_filter($state['captures'], fn ($c) => $this->canViewCapture($c['worksetId'] ?? null)));

        return $state;
    }

    /**
     * An event as this code's clients get it: unchanged, redacted (`{seq, opId, redacted}`:
     * the client only advances its sequence number and settles its own operation) or, for a
     * merge that can't be taken apart, a request to fetch a fresh snapshot (`resync`).
     *
     * @param array{seq: int, op: array} $event
     */
    public function filterEvent(array $event): array
    {
        if ($this->isFull()) {
            return $event;
        }
        $op = $event['op'];
        $visible = match ($op['type'] ?? null) {
            'state.merge' => null,
            'workset.add' => is_string($op['workset']['id'] ?? null) && $this->canViewWorkset($op['workset']['id']),
            'workset.rename', 'workset.delete', 'workset.makeDefault', 'workset.setKind',
            'workset.ranking.add', 'workset.ranking.remove', 'workset.ranking.move' => is_string($op['worksetId'] ?? null) && $this->canViewWorkset($op['worksetId']),
            'capture.add' => $this->canViewCapture(is_array($op['capture'] ?? null) ? ($op['capture']['worksetId'] ?? null) : null),
            default => true,
        };
        if (true === $visible) {
            return $event;
        }

        return ['seq' => $event['seq'], 'opId' => $op['opId'] ?? null] + (null === $visible ? ['resync' => true] : ['redacted' => true]);
    }
}

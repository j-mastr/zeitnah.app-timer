<?php

// Checks what the server lets a restricted access code see and do (App\Access\Access):
// projection of the state, filtering of events, and revocation once its worksets are gone.
//
// Usage: php tests/access-scope.php   (requires `composer install`)

use App\Access\Access;
use App\Access\Permissions;
use App\Race\OperationReducer;

require dirname(__DIR__).'/vendor/autoload.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        echo "FAILED: $what\n";
    }
}

$reducer = new OperationReducer();
$state = OperationReducer::emptyState();
foreach ([
    ['type' => 'participants.add', 'participants' => [['id' => 'p1', 'name' => 'A'], ['id' => 'p2', 'name' => 'B']]],
    ['type' => 'workset.add', 'workset' => ['id' => 'w1']],
    ['type' => 'workset.add', 'workset' => ['id' => 'w2', 'name' => 'Gate']],
    ['type' => 'workset.ranking.add', 'worksetId' => 'w2', 'participantId' => 'p1'],
    ['type' => 'capture.add', 'capture' => ['id' => 'c1', 'ts' => 1, 'participantId' => 'p1', 'worksetId' => 'w1']],
    ['type' => 'capture.add', 'capture' => ['id' => 'c2', 'ts' => 2, 'participantId' => 'p2', 'worksetId' => 'w2']],
    ['type' => 'capture.add', 'capture' => ['id' => 'c3', 'ts' => 3, 'participantId' => null]],
] as $op) {
    $state = $reducer->apply($state, $op);
}

$full = new Access(1, 'RACE01', Permissions::FULL);
$station = new Access(1, 'STAT01', Permissions::worksetRules('w1'));
$viewOnly = new Access(1, 'VIEW01', ['race.view', 'participant.view', 'kind.view', 'workset.view', 'capture.view', 'workset.capture.view']);

// Full access sees and may do everything.
check($full->project($state) === $state, 'full access sees the whole state');
check(null === $full->checkOperation($state, ['type' => 'race.rename', 'name' => 'x']), 'full access may rename the race');

// A station code: its own workset, every capture listed, only its own editable.
$projected = $station->project($state);
check(['w1'] === array_column($projected['worksets'], 'id'), 'a station code sees only its workset');
check(['c1', 'c2', 'c3'] === array_column($projected['captures'], 'id'), 'a station code lists every capture');
check(2 === count($projected['participants']), 'a station code sees the participants');
foreach ([
    ['type' => 'race.rename', 'name' => 'x'], ['type' => 'race.setSport', 'sport' => 'running'], ['type' => 'race.archive'],
    ['type' => 'participants.add', 'participants' => []], ['type' => 'participant.rename', 'participantId' => 'p1', 'name' => 'x'],
    ['type' => 'kind.add', 'kind' => ['id' => 'k', 'name' => 'K', 'role' => 'marker']], ['type' => 'state.merge', 'state' => []],
    ['type' => 'workset.add', 'workset' => ['id' => 'w3']], ['type' => 'workset.rename', 'worksetId' => 'w1', 'name' => 'x'],
    ['type' => 'workset.delete', 'worksetId' => 'w1'], ['type' => 'workset.makeDefault', 'worksetId' => 'w1'],
    ['type' => 'workset.ranking.add', 'worksetId' => 'w2', 'participantId' => 'p2'], ['type' => 'workset.setKind', 'worksetId' => 'w2', 'kind' => 'start'],
    ['type' => 'capture.assign', 'captureId' => 'c2', 'participantId' => null], ['type' => 'capture.delete', 'captureId' => 'c3'],
    ['type' => 'capture.add', 'capture' => ['id' => 'c9', 'ts' => 9, 'participantId' => null]],
    ['type' => 'groupType.add', 'groupType' => ['id' => 't', 'name' => 'Fleet']], ['type' => 'group.add', 'group' => ['id' => 'g', 'name' => 'A']],
    ['type' => 'group.members.add', 'groupId' => 'g', 'refs' => []], ['type' => 'group.delete', 'groupId' => 'g'],
    ['type' => 'capture.target.add', 'captureId' => 'c2', 'target' => ['type' => 'participant', 'id' => 'p1']],
    ['type' => 'capture.target.remove', 'captureId' => 'c3', 'target' => ['type' => 'participant', 'id' => 'p1']],
] as $op) {
    check('forbidden' === $station->checkOperation($state, $op), 'a station code may not '.$op['type'].' '.json_encode($op));
}
foreach ([
    ['type' => 'workset.setKind', 'worksetId' => 'w1', 'kind' => 'start'], ['type' => 'workset.ranking.add', 'worksetId' => 'w1', 'participantId' => 'p1'],
    ['type' => 'workset.ranking.move', 'worksetId' => 'w1', 'participantId' => 'p1', 'beforeId' => null],
    ['type' => 'capture.add', 'capture' => ['id' => 'c9', 'ts' => 9, 'participantId' => null, 'worksetId' => 'w1']],
    ['type' => 'capture.assign', 'captureId' => 'c1', 'participantId' => 'p2'], ['type' => 'capture.delete', 'captureId' => 'c1'],
    ['type' => 'capture.delete', 'captureId' => 'gone'],
    ['type' => 'capture.target.add', 'captureId' => 'c1', 'target' => ['type' => 'participant', 'id' => 'p2']],
    ['type' => 'capture.target.remove', 'captureId' => 'c1', 'target' => ['type' => 'participant', 'id' => 'p1']],
    ['type' => 'capture.add', 'capture' => ['id' => 'c9', 'ts' => 9, 'targets' => [['type' => 'participant', 'id' => 'p1']], 'worksetId' => 'w1']],
] as $op) {
    check(null === $station->checkOperation($state, $op), 'a station code may '.json_encode($op));
}

// Events: other worksets are redacted, merges ask for a snapshot, the rest passes.
$event = static fn (array $op) => ['seq' => 7, 'op' => ['opId' => 'o7'] + $op];
check(['seq' => 7, 'opId' => 'o7', 'redacted' => true] === $station->filterEvent($event(['type' => 'workset.ranking.add', 'worksetId' => 'w2', 'participantId' => 'p1'])), 'ranking of another workset is redacted');
check(['seq' => 7, 'opId' => 'o7', 'redacted' => true] === $station->filterEvent($event(['type' => 'workset.add', 'workset' => ['id' => 'w3']])), 'a new workset elsewhere is redacted');
check(['seq' => 7, 'opId' => 'o7', 'resync' => true] === $station->filterEvent($event(['type' => 'state.merge', 'state' => []])), 'a merge asks for a snapshot');
$own = $event(['type' => 'workset.ranking.add', 'worksetId' => 'w1', 'participantId' => 'p1']);
check($own === $station->filterEvent($own), 'its own workset passes');
$capture = $event(['type' => 'capture.add', 'capture' => ['id' => 'c9', 'ts' => 9, 'worksetId' => 'w2']]);
check($capture === $station->filterEvent($capture), 'captures of other worksets are listed');

// Revocation: once its workset is deleted; access comes back with it (undo).
check(!$station->isRevokedIn($state), 'a station code is valid while its workset exists');
$deleted = $reducer->apply($state, ['type' => 'workset.delete', 'worksetId' => 'w1']);
check($station->isRevokedIn($deleted), 'deleting its workset revokes a station code');
check(!$full->isRevokedIn($deleted) && !$viewOnly->isRevokedIn($reducer->apply($deleted, ['type' => 'workset.delete', 'worksetId' => 'w2'])),
    'codes not restricted to worksets are never revoked by deletions');
check(!$station->isRevokedIn($reducer->apply($deleted, ['type' => 'workset.add', 'workset' => ['id' => 'w1']])), 'restoring the workset restores the access');
$two = new Access(1, 'TWO001', ['workset[w1].*', 'workset[w2].*']);
check(!$two->isRevokedIn($deleted), 'a code with a workset left keeps its access');
check((new Access(1, 'GONE01', Permissions::FULL, true))->isRevokedIn($state), 'an explicitly revoked code is revoked');

echo $failures ? "$failures check(s) failed\n" : "Access scope OK\n";
exit($failures > 0 ? 1 : 0);

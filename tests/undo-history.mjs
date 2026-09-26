#!/usr/bin/env node
// Checks the undo/redo history of frontend/index.html (historyEntry and friends):
//  - random operation sequences, undone in reverse order, lead back to the initial state,
//    and redone in order lead to the final state again (participant and capture order
//    aside, which undo can't preserve and the UI doesn't depend on),
//  - an operation without a history entry really changed nothing,
//  - a change made elsewhere to what an entry touched is detected as a conflict.
//
// Usage: node tests/undo-history.mjs [cases=300]
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = fs.readFileSync(path.join(root, 'frontend/index.html'), 'utf8');
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].pop()[1];
const start = script.indexOf('function uid()');
const end = script.indexOf('// Backends');
if (start < 0 || end < 0) throw new Error('Could not locate the reducer and history in frontend/index.html');
// The constants the reducer needs (SPORTS, ID_RE, the kinds) precede the text table.
const constStart = script.indexOf('const SPORTS');
const constEnd = script.indexOf('const TEXTS');
if (constStart < 0 || constEnd < 0) throw new Error('Could not locate the constants in frontend/index.html');
const preamble = script.slice(constStart, constEnd);
const {applyOp, emptyState, historyEntry, footprint, footprintAfter} = new Function(
  preamble + script.slice(start, script.lastIndexOf('\n', end)) + '\n; return {applyOp, emptyState, historyEntry, footprint, footprintAfter};')();

let seed = 7;
const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
const pick = (list) => list[Math.floor(rnd() * list.length)];
const clone = (v) => JSON.parse(JSON.stringify(v));

const participantIds = ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'];
const captureIds = ['c1', 'c2', 'c3', 'c4', 'c5'];
const names = ['GER 1', 'ger 1', 'NED 7', 'FRA 12', 'Ö-Team', 'ITA 3'];
const kindIds = ['k1', 'k2', 'k3'];
const captureKinds = ['start', 'split', 'finish', ...kindIds];
const worksetIds = ['w1', 'w2', 'w3'];

const groupIds = ['g1', 'g2', 'g3', 'g4'];
const fieldIds = ['f1', 'f2'];
const fieldValue = (f) => (f === 'f1' ? pick([95, 102, 110, null]) : pick(['KYC', 'NRV', null, 3]));
const randomRule = () => pick([null, {all: [{field: 'f1', op: 'range', min: pick([null, 100]), max: 105}]}, {all: [{field: 'f2', op: 'eq', value: 'KYC'}]}]);
const groupTypeIds = ['t1', 't2'];
const randomRefs = () => Array.from({length: 1 + Math.floor(rnd() * 3)},
  () => (rnd() < 0.6 ? {type: 'participant', id: pick(participantIds)} : {type: 'group', id: pick(groupIds)}));
const randomTargets = () => Array.from({length: Math.floor(rnd() * 4)}, () => ({type: 'participant', id: pick(participantIds)}));
function randomOp(i) {
  const type = pick(['race.rename', 'race.setSport', 'participants.add', 'participants.add', 'participant.rename', 'participant.delete',
    'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.remove', 'workset.ranking.move', 'workset.ranking.move',
    'capture.add', 'capture.add', 'capture.assign', 'capture.delete', 'capture.target.add', 'capture.target.remove',
    'capture.setKind', 'workset.setKind', 'kind.add', 'kind.add', 'kind.update', 'kind.delete',
    'workset.add', 'workset.add', 'workset.rename', 'workset.delete', 'workset.makeDefault',
    'groupType.add', 'groupType.update', 'groupType.delete', 'group.add', 'group.add', 'group.add', 'group.update', 'group.delete',
    'group.members.add', 'group.members.add', 'group.members.add', 'group.members.remove',
    'field.add', 'field.add', 'field.update', 'field.delete', 'participant.setMeta', 'participant.setMeta', 'participant.setMeta', 'group.setRule']);
  switch (type) {
    case 'race.rename': return {type, name: pick(['Kieler Woche', null, 'Cup'])};
    case 'race.setSport': return {type, sport: pick(['generic', 'sailing', 'running'])};
    case 'participants.add':
      return {type, participants: [{id: pick(participantIds), name: pick(names), meta: rnd() < 0.4 ? [{field: 'f1', value: 101}, {field: 'f2', value: 'KYC'}] : undefined},
        {id: pick(participantIds), name: pick(names)}]};
    case 'field.add': return {type, field: {id: pick(fieldIds), name: pick(['Yardstick', 'Club', 'yardstick']), type: 'f1' === fieldIds[0] && rnd() < 0.5 ? 'number' : 'text'}};
    case 'field.update': return {type, fieldId: pick(fieldIds), name: 'Field ' + i};
    case 'field.delete': return {type, fieldId: pick(fieldIds)};
    case 'participant.setMeta': { const f = pick(fieldIds); return {type, participantId: pick(participantIds), fieldId: f, value: fieldValue(f)}; }
    case 'group.setRule': return {type, groupId: pick(groupIds), rule: randomRule()};
    case 'participant.rename': return {type, participantId: pick(participantIds), name: pick(names) + ' ' + i};
    case 'participant.delete': return {type, participantId: pick(participantIds)};
    case 'workset.ranking.add': case 'workset.ranking.remove':
      return rnd() < 0.5 ? {type, worksetId: pick(worksetIds), participantId: pick(participantIds)} : {type, worksetId: pick(worksetIds), ref: randomRefs()[0]};
    case 'workset.ranking.move':
      return rnd() < 0.5 ? {type, worksetId: pick(worksetIds), participantId: pick(participantIds), beforeId: pick([...participantIds, null])}
        : {type, worksetId: pick(worksetIds), ref: randomRefs()[0], before: pick([null, ...randomRefs()])};
    case 'workset.add': return {type, workset: {id: pick(worksetIds), name: pick([null, null, 'Gate', 'gate', 'Finish'])}, beforeId: pick([null, ...worksetIds])};
    case 'workset.rename': return {type, worksetId: pick(worksetIds), name: pick([null, 'Gate', 'Finish ' + i])};
    case 'workset.delete': case 'workset.makeDefault': return {type, worksetId: pick(worksetIds)};
    case 'capture.add':
      return {type, capture: {id: pick(captureIds), ts: 1790000000000 + i, tzOffset: pick([120, null]),
        ...(rnd() < 0.5 ? {participantId: pick([...participantIds, null])} : {targets: randomTargets()}),
        kind: pick(captureKinds), worksetId: pick([null, ...worksetIds])}};
    case 'capture.assign':
      return rnd() < 0.5 ? {type, captureId: pick(captureIds), participantId: pick([...participantIds, null])}
        : {type, captureId: pick(captureIds), targets: randomTargets()};
    case 'capture.target.add': case 'capture.target.remove':
      return {type, captureId: pick(captureIds), target: {type: 'participant', id: pick(participantIds)}};
    case 'capture.delete': return {type, captureId: pick(captureIds)};
    case 'capture.setKind': return {type, captureId: pick(captureIds), kind: pick(captureKinds)};
    case 'workset.setKind': return {type, worksetId: pick(worksetIds), kind: pick(captureKinds)};
    case 'kind.add': return {type, kind: {id: pick(kindIds), name: pick(['Protest', 'protest', 'Gate', 'Pit']), role: pick(['split', 'marker'])}};
    case 'kind.update': return {type, kindId: pick(kindIds), name: pick(['Protest', 'Gate', 'Pit']) + ' ' + i, role: pick(['split', 'marker'])};
    case 'kind.delete': return {type, kindId: pick(kindIds)};
    case 'groupType.add': return {type, groupType: {id: pick(groupTypeIds), name: pick(['Fleet', 'Class', 'fleet']), exclusive: rnd() < 0.5}, beforeId: pick([null, ...groupTypeIds])};
    case 'groupType.update': return {type, groupTypeId: pick(groupTypeIds), name: pick(['Fleet', 'Class']) + ' ' + i, exclusive: rnd() < 0.5};
    case 'groupType.delete': return {type, groupTypeId: pick(groupTypeIds)};
    case 'group.add':
      return {type, group: {id: pick(groupIds), typeId: pick([null, ...groupTypeIds]), name: pick(['A', 'B', 'a', 'Gold']), members: rnd() < 0.3 ? randomRefs() : undefined},
        beforeId: pick([null, ...groupIds])};
    case 'group.update': return {type, groupId: pick(groupIds), name: pick(['A', 'B', 'Silver']) + ' ' + i, typeId: pick([null, ...groupTypeIds])};
    case 'group.delete': return {type, groupId: pick(groupIds)};
    case 'group.members.add': case 'group.members.remove': return {type, groupId: pick(groupIds), refs: randomRefs()};
  }
}

const byId = (a, b) => (a.id < b.id ? -1 : 1);
// Workset order matters (the first one is the default), so it is compared as it is.
const canonical = (s) => JSON.stringify({...s, participants: [...s.participants].sort(byId), kinds: [...s.kinds].sort(byId), captures: [...s.captures].sort(byId)});
const replay = (s, ops) => { for (const op of ops) applyOp(s, clone(op)); return s; };

const cases = parseInt(process.argv[2] || '300', 10);
let recorded = 0, irreversible = 0;
for (let n = 0; n < cases; n++) {
  const state = replay(emptyState(), Array.from({length: 1 + Math.floor(rnd() * 8)}, (_, i) => randomOp(i)));
  let baseline = clone(state);
  const history = [];
  for (let i = 0; i < 12; i++) {
    const op = randomOp(100 + i);
    const beforeState = clone(state);
    const entry = historyEntry(state, op);
    const before = entry && footprint(state, entry.parts);
    applyOp(state, op);
    const after = entry && footprint(state, entry.parts);
    if (entry && after !== before) {
      if (footprintAfter(state, entry.undo, entry.parts) !== before) {
        // Only a capture of a deleted participant can't be restored: the reducers refuse to
        // assign a capture to an unknown participant. The app refuses this undo with a toast;
        // the steps before it are checked from here on.
        const c = beforeState.captures.find((x) => x.id === (op.captureId || (op.capture && op.capture.id)));
        assert.ok(c && c.participantId && !beforeState.participants.some((p) => p.id === c.participantId), `undo of ${op.type} reaches its footprint`);
        irreversible++;
        history.length = 0;
        baseline = clone(state);
        continue;
      }
      history.push(entry);
    } else {
      assert.equal(canonical(state), canonical(beforeState), `${op.type} without a history entry changed the state`);
    }
  }
  recorded += history.length;
  const final = clone(state);
  for (const entry of [...history].reverse()) replay(state, entry.undo);
  assert.equal(canonical(state), canonical(baseline), 'undoing everything restores the initial state');
  for (const entry of history) replay(state, entry.redo);
  assert.equal(canonical(state), canonical(final), 'redoing everything restores the final state');
}

// A capture of several ranked participants: undo puts them all back in their order.
{
  const s = replay(emptyState(), [
    {type: 'participants.add', participants: ['a', 'b', 'c', 'd'].map((id) => ({id, name: id.toUpperCase()}))},
    {type: 'workset.add', workset: {id: 'w'}},
    ...['a', 'b', 'c', 'd'].map((id) => ({type: 'workset.ranking.add', worksetId: 'w', participantId: id})),
  ]);
  const before = clone(s);
  const op = {type: 'capture.add', capture: {id: 'x', ts: 1, tzOffset: 0, kind: 'finish', worksetId: 'w',
    targets: [{type: 'participant', id: 'c'}, {type: 'participant', id: 'b'}]}};
  const entry = historyEntry(s, op);
  applyOp(s, clone(op));
  assert.deepEqual(s.worksets[0].ranking, [{type: 'participant', id: 'a'}, {type: 'participant', id: 'd'}]);
  replay(s, entry.undo);
  assert.equal(canonical(s), canonical(before), 'undoing a capture of several participants restores the ranking');
}

// Groups: deleting a member participant or a nested group, removing members — undo restores it all.
{
  const P = (id) => ({type: 'participant', id}), G = (id) => ({type: 'group', id});
  const s = replay(emptyState(), [
    {type: 'participants.add', participants: ['a', 'b', 'c'].map((id) => ({id, name: id.toUpperCase()}))},
    {type: 'groupType.add', groupType: {id: 't', name: 'Fleet', exclusive: true}},
    {type: 'group.add', group: {id: 'g1', typeId: 't', name: 'A', members: [P('a'), P('b')]}},
    {type: 'group.add', group: {id: 'g2', name: 'Wave', members: [G('g1'), P('c')]}},
    {type: 'group.add', group: {id: 'g3', name: 'Other', members: [P('a')]}},
  ]);
  for (const op of [
    {type: 'participant.delete', participantId: 'a'},
    {type: 'group.delete', groupId: 'g1'},
    {type: 'group.members.remove', groupId: 'g2', refs: [G('g1'), P('c'), P('b')]},
    {type: 'groupType.delete', groupTypeId: 't'},
    {type: 'group.members.add', groupId: 'g1', refs: [G('g2')]},   // would close a cycle: nothing to undo
  ]) {
    const before = clone(s);
    const entry = historyEntry(s, op);
    if (op.type === 'group.members.add') { assert.equal(entry, null, 'a cycle-closing member is no step'); continue; }
    applyOp(s, clone(op));
    assert.equal(footprintAfter(s, entry.undo, entry.parts), footprint(before, entry.parts), `${op.type} can be undone`);
    replay(s, entry.undo);
    assert.equal(canonical(s), canonical(before), `undoing ${op.type} restores the groups`);
  }
}

// Ranked groups: a start for a ranked fleet, deleting a ranked group — undo restores the ranking.
{
  const P = (id) => ({type: 'participant', id}), G = (id) => ({type: 'group', id});
  const s = replay(emptyState(), [
    {type: 'participants.add', participants: ['a', 'b'].map((id) => ({id, name: id.toUpperCase()}))},
    {type: 'group.add', group: {id: 'g1', name: 'Fleet A', members: [P('a')]}},
    {type: 'group.add', group: {id: 'g2', name: 'Fleet B', members: [P('b')]}},
    {type: 'workset.add', workset: {id: 'w'}},
    {type: 'workset.ranking.add', worksetId: 'w', ref: G('g1')},
    {type: 'workset.ranking.add', worksetId: 'w', ref: P('b')},
    {type: 'workset.ranking.add', worksetId: 'w', ref: G('g2')},
  ]);
  for (const op of [
    {type: 'capture.add', capture: {id: 'x', ts: 1, kind: 'start', worksetId: 'w', targets: [G('g1'), G('g2')]}},
    {type: 'group.delete', groupId: 'g2'},
    {type: 'workset.ranking.move', worksetId: 'w', ref: G('g2'), before: G('g1')},
  ]) {
    const before = clone(s);
    const entry = historyEntry(s, op);
    applyOp(s, clone(op));
    assert.equal(footprintAfter(s, entry.undo, entry.parts), footprint(before, entry.parts), `${op.type} can be undone`);
    replay(s, entry.undo);
    assert.equal(canonical(s), canonical(before), `undoing ${op.type} restores the ranking`);
  }
}

// Fields: deleting a field or a participant with values, changing a value or a rule — undo restores them.
{
  const s = replay(emptyState(), [
    {type: 'field.add', field: {id: 'ys', name: 'Yardstick', type: 'number'}},
    {type: 'field.add', field: {id: 'club', name: 'Club', type: 'text'}},
    {type: 'participants.add', participants: [{id: 'a', name: 'A', meta: [{field: 'ys', value: 101}, {field: 'club', value: 'KYC'}]}, {id: 'b', name: 'B', meta: [{field: 'ys', value: 96}]}]},
    {type: 'group.add', group: {id: 'g', name: 'Fast', rule: {all: [{field: 'ys', op: 'range', max: 100}]}}},
  ]);
  for (const op of [
    {type: 'field.delete', fieldId: 'ys'},
    {type: 'participant.delete', participantId: 'a'},
    {type: 'participant.setMeta', participantId: 'b', fieldId: 'ys', value: null},
    {type: 'participant.setMeta', participantId: 'b', fieldId: 'club', value: 'NRV'},
    {type: 'group.setRule', groupId: 'g', rule: null},
    {type: 'group.delete', groupId: 'g'},
  ]) {
    const before = clone(s);
    const entry = historyEntry(s, op);
    applyOp(s, clone(op));
    assert.equal(footprintAfter(s, entry.undo, entry.parts), footprint(before, entry.parts), `${op.type} can be undone`);
    replay(s, entry.undo);
    assert.equal(canonical(s), canonical(before), `undoing ${op.type} restores fields, values and rules`);
  }
}

// Conflicts: another device changed what the entry touched.
{
  const s = replay(emptyState(), [
    {type: 'participants.add', participants: [{id: 'a', name: 'A'}, {id: 'b', name: 'B'}]},
    {type: 'capture.add', capture: {id: 'c', ts: 1, tzOffset: 0, participantId: null}},
  ]);
  const op = {type: 'capture.assign', captureId: 'c', participantId: 'a'};
  const entry = historyEntry(s, op);
  applyOp(s, op);
  const after = footprint(s, entry.parts);
  applyOp(s, {type: 'capture.assign', captureId: 'c', participantId: 'b'});   // elsewhere
  assert.notEqual(footprint(s, entry.parts), after, 'a reassignment elsewhere is a conflict');
}
{
  // Undo of a delete that can't be restored any more: the name is taken again meanwhile.
  const s = replay(emptyState(), [{type: 'participants.add', participants: [{id: 'a', name: 'A'}]}]);
  const op = {type: 'participant.delete', participantId: 'a'};
  const entry = historyEntry(s, op);
  const before = footprint(s, entry.parts);
  applyOp(s, op);
  applyOp(s, {type: 'participants.add', participants: [{id: 'x', name: 'a'}]});   // elsewhere
  assert.notEqual(footprintAfter(s, entry.undo, entry.parts), before, 'an undo that would not restore the state is refused');
}
{
  // Undoing a recorded time puts the participant back at its old ranking position.
  const s = replay(emptyState(), [
    {type: 'participants.add', participants: [{id: 'a', name: 'A'}, {id: 'b', name: 'B'}, {id: 'c', name: 'C'}]},
    {type: 'workset.add', workset: {id: 'w'}}, {type: 'workset.add', workset: {id: 'v'}},
    ...['a', 'b', 'c'].flatMap((p) => [{type: 'workset.ranking.add', worksetId: 'w', participantId: p}, {type: 'workset.ranking.add', worksetId: 'v', participantId: p}]),
  ]);
  const op = {type: 'capture.add', capture: {id: 'k', ts: 1, tzOffset: 0, participantId: 'b', worksetId: 'w'}};
  const entry = historyEntry(s, op);
  applyOp(s, op);
  // Only the capturing workset's ranking loses the participant.
  assert.deepEqual(s.worksets.map((w) => w.ranking.map((r) => r.id)), [['a', 'c'], ['a', 'b', 'c']]);
  replay(s, entry.undo);
  assert.deepEqual(s.worksets.map((w) => w.ranking.map((r) => r.id)), [['a', 'b', 'c'], ['a', 'b', 'c']]);
  assert.equal(s.captures.length, 0);
}
{
  // A marker (e.g. a protest) leaves the participant in the ranking; undo keeps it there.
  const s = replay(emptyState(), [
    {type: 'participants.add', participants: [{id: 'a', name: 'A'}, {id: 'b', name: 'B'}]},
    {type: 'workset.add', workset: {id: 'w'}},
    {type: 'workset.ranking.add', worksetId: 'w', participantId: 'a'}, {type: 'workset.ranking.add', worksetId: 'w', participantId: 'b'},
    {type: 'kind.add', kind: {id: 'p', name: 'Protest', role: 'marker'}},
  ]);
  const op = {type: 'capture.add', capture: {id: 'k', ts: 1, tzOffset: 0, participantId: 'a', kind: 'p', worksetId: 'w'}};
  const entry = historyEntry(s, op);
  applyOp(s, op);
  assert.deepEqual(s.worksets[0].ranking.map((r) => r.id), ['a', 'b']);
  replay(s, entry.undo);
  assert.deepEqual(s.worksets[0].ranking.map((r) => r.id), ['a', 'b']);
  assert.equal(s.captures.length, 0);
}
{
  // Undoing the deletion of the selected kind selects it again.
  const s = replay(emptyState(), [
    {type: 'kind.add', kind: {id: 'p', name: 'Protest', role: 'marker'}},
    {type: 'workset.add', workset: {id: 'w'}}, {type: 'workset.add', workset: {id: 'v'}},
    {type: 'workset.setKind', worksetId: 'w', kind: 'p'},
  ]);
  const op = {type: 'kind.delete', kindId: 'p'};
  const entry = historyEntry(s, op);
  applyOp(s, op);
  assert.deepEqual(s.worksets.map((w) => w.captureKind), ['finish', 'finish']);
  replay(s, entry.undo);
  assert.deepEqual(s.worksets.map((w) => w.captureKind), ['p', 'finish']);
  assert.deepEqual(s.kinds, [{id: 'p', name: 'Protest', role: 'marker'}]);
}
{
  // Deleting a workset and undoing it restores it in place, with number, name, ranking and kind.
  const s = replay(emptyState(), [
    {type: 'participants.add', participants: [{id: 'a', name: 'A'}]},
    {type: 'workset.add', workset: {id: 'w'}}, {type: 'workset.add', workset: {id: 'v', name: 'Gate'}}, {type: 'workset.add', workset: {id: 'x'}},
    {type: 'workset.ranking.add', worksetId: 'v', participantId: 'a'}, {type: 'workset.setKind', worksetId: 'v', kind: 'start'},
  ]);
  const before = clone(s);
  const op = {type: 'workset.delete', worksetId: 'v'};
  const entry = historyEntry(s, op);
  applyOp(s, op);
  assert.deepEqual(s.worksets.map((w) => w.id), ['w', 'x']);
  replay(s, entry.undo);
  assert.deepEqual(s, before);
}
{
  // Making a workset the default and undoing it restores the order.
  const s = replay(emptyState(), ['a', 'b', 'c', 'd'].map((id) => ({type: 'workset.add', workset: {id}})));
  const op = {type: 'workset.makeDefault', worksetId: 'c'};
  const entry = historyEntry(s, op);
  applyOp(s, op);
  assert.deepEqual(s.worksets.map((w) => w.id), ['c', 'a', 'b', 'd']);
  replay(s, entry.undo);
  assert.deepEqual(s.worksets.map((w) => w.id), ['a', 'b', 'c', 'd']);
  // The last workset deleted: numbering starts again.
  for (const id of ['a', 'b', 'c', 'd']) applyOp(s, {type: 'workset.delete', worksetId: id});
  applyOp(s, {type: 'workset.add', workset: {id: 'e'}});
  assert.equal(s.worksets[0].number, 1);
}

console.log(`Undo history OK: ${cases} sequences, ${recorded} recorded steps undone and redone, ${irreversible} refused as irreversible.`);

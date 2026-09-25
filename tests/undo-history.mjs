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

function randomOp(i) {
  const type = pick(['race.rename', 'race.setSport', 'participants.add', 'participants.add', 'participant.rename', 'participant.delete',
    'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.remove', 'workset.ranking.move', 'workset.ranking.move',
    'capture.add', 'capture.add', 'capture.assign', 'capture.delete',
    'capture.setKind', 'workset.setKind', 'kind.add', 'kind.add', 'kind.update', 'kind.delete',
    'workset.add', 'workset.add', 'workset.rename', 'workset.delete', 'workset.makeDefault']);
  switch (type) {
    case 'race.rename': return {type, name: pick(['Kieler Woche', null, 'Cup'])};
    case 'race.setSport': return {type, sport: pick(['generic', 'sailing', 'running'])};
    case 'participants.add':
      return {type, participants: [{id: pick(participantIds), name: pick(names)}, {id: pick(participantIds), name: pick(names)}]};
    case 'participant.rename': return {type, participantId: pick(participantIds), name: pick(names) + ' ' + i};
    case 'participant.delete': return {type, participantId: pick(participantIds)};
    case 'workset.ranking.add': case 'workset.ranking.remove': return {type, worksetId: pick(worksetIds), participantId: pick(participantIds)};
    case 'workset.ranking.move': return {type, worksetId: pick(worksetIds), participantId: pick(participantIds), beforeId: pick([...participantIds, null])};
    case 'workset.add': return {type, workset: {id: pick(worksetIds), name: pick([null, null, 'Gate', 'gate', 'Finish'])}, beforeId: pick([null, ...worksetIds])};
    case 'workset.rename': return {type, worksetId: pick(worksetIds), name: pick([null, 'Gate', 'Finish ' + i])};
    case 'workset.delete': case 'workset.makeDefault': return {type, worksetId: pick(worksetIds)};
    case 'capture.add':
      return {type, capture: {id: pick(captureIds), ts: 1790000000000 + i, tzOffset: pick([120, null]), participantId: pick([...participantIds, null]),
        kind: pick(captureKinds), worksetId: pick([null, ...worksetIds])}};
    case 'capture.assign': return {type, captureId: pick(captureIds), participantId: pick([...participantIds, null])};
    case 'capture.delete': return {type, captureId: pick(captureIds)};
    case 'capture.setKind': return {type, captureId: pick(captureIds), kind: pick(captureKinds)};
    case 'workset.setKind': return {type, worksetId: pick(worksetIds), kind: pick(captureKinds)};
    case 'kind.add': return {type, kind: {id: pick(kindIds), name: pick(['Protest', 'protest', 'Gate', 'Pit']), role: pick(['split', 'marker'])}};
    case 'kind.update': return {type, kindId: pick(kindIds), name: pick(['Protest', 'Gate', 'Pit']) + ' ' + i, role: pick(['split', 'marker'])};
    case 'kind.delete': return {type, kindId: pick(kindIds)};
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
  assert.deepEqual(s.worksets.map((w) => w.ranking), [['a', 'c'], ['a', 'b', 'c']]);
  replay(s, entry.undo);
  assert.deepEqual(s.worksets.map((w) => w.ranking), [['a', 'b', 'c'], ['a', 'b', 'c']]);
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
  assert.deepEqual(s.worksets[0].ranking, ['a', 'b']);
  replay(s, entry.undo);
  assert.deepEqual(s.worksets[0].ranking, ['a', 'b']);
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

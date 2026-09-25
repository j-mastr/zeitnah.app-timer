#!/usr/bin/env node
// Checks that the JavaScript reducer in frontend/index.html (applyOp) and the PHP
// reducer (App\Race\OperationReducer) produce identical states and identical
// error codes for the same random operation sequences, including invalid input.
//
// Usage: node tests/reducer-parity.mjs [cases=200]   (requires `composer install`)
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = fs.readFileSync(path.join(root, 'frontend/index.html'), 'utf8');
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].pop()[1];
const start = script.indexOf('function uid()');
const end = script.indexOf('// Operations that stay possible');
if (start < 0 || end < 0) throw new Error('Could not locate the reducer in frontend/index.html');
// The constants the reducer needs (SPORTS, ID_RE, the kinds) precede the text table.
const constStart = script.indexOf('const SPORTS');
const constEnd = script.indexOf('const TEXTS');
if (constStart < 0 || constEnd < 0) throw new Error('Could not locate the constants in frontend/index.html');
const preamble = script.slice(constStart, constEnd);
const {applyOp, emptyState, normalizeState, SCHEMA_VERSION} = new Function(
  preamble + script.slice(start, end) + '; return {applyOp, emptyState, normalizeState, SCHEMA_VERSION};')();

let seed = 42;
const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
const pick = (list) => list[Math.floor(rnd() * list.length)];

const participantIds = ['b1', 'b2', 'b3', 'b4', 'b5', 'bX', 'b1', 'b2', 'b3', 'not valid!'];
const captureIds = ['c1', 'c2', 'c3', 'c4'];
const names = ['GER 1', 'ger 1', '  NED  7 ', 'Ö-Team', '', 'x'.repeat(61), 'FRA 12', 42, '🏁 Emoji', 'ITA 3', 'ESP 9', 'DEN 4', 'SWE 2'];
const kindIds = ['k1', 'k2', 'k3', 'start', 'split', 'finish', 'not valid!'];
const captureKinds = [...kindIds, null, undefined, 7];
const kindNames = ['Protest', 'protest', '  Pit  in ', '', 'y'.repeat(41), 3];
const kindRoles = ['split', 'marker', 'marker', 'finish', null];
const randomKind = () => ({id: pick(kindIds), name: pick(kindNames), role: pick(kindRoles)});
const worksetIds = ['w1', 'w2', 'w3', 'w4', 'not valid!'];
const worksetRefs = ['w1', 'w1', 'w1', 'w2', 'w2', 'w2', 'w3', 'w4', 'not valid!', null, undefined, 5];
const worksetNames = ['Finish', 'finish', ' Gate  3 ', '', null, null, undefined, undefined, 'z'.repeat(41), 4];
const rankings = [undefined, null, [], ['b1', 'b2', 'b1', 'bX'], ['b3', 'b2'], ['not valid!'], 'b1', [7]];
const randomWorkset = () => (rnd() < 0.1 ? pick([null, 'w1', {}]) : {
  id: pick(worksetIds), name: pick(worksetNames), number: pick([undefined, undefined, undefined, undefined, null, 3, 0, 1.5, '2']),
  ranking: pick(rankings), captureKind: pick([undefined, undefined, ...captureKinds]),
});
const types = ['race.rename', 'race.archive', 'race.setSport', 'participants.add', 'participants.add', 'participants.add', 'participant.rename', 'participant.delete',
  'capture.add', 'capture.add', 'capture.assign', 'capture.delete', 'capture.setKind',
  'kind.add', 'kind.add', 'kind.update', 'kind.delete', 'state.merge', 'bogus',
  'workset.add', 'workset.add', 'workset.add', 'workset.rename', 'workset.delete', 'workset.makeDefault', 'workset.setKind',
  'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.remove', 'workset.ranking.move', 'workset.ranking.move'];

function randomOp(i) {
  const type = pick(types);
  const op = {type};
  switch (type) {
    case 'race.rename': op.name = pick(['Kieler Woche', null, '   ', 'y'.repeat(81)]); break;
    case 'race.setSport': op.sport = pick(['generic', 'sailing', 'running', 'swimming', 'motor', 'bogus', null]); break;
    case 'participants.add': op.participants = [{id: pick(participantIds), name: pick(names)}, {id: pick(participantIds), name: pick(names)}]; break;
    case 'participant.rename': op.participantId = pick(participantIds); op.name = pick(names); break;
    case 'participant.delete': op.participantId = pick(participantIds); break;
    case 'workset.ranking.add': case 'workset.ranking.remove':
      op.worksetId = pick(worksetRefs); op.participantId = pick(participantIds); break;
    case 'workset.ranking.move':
      op.worksetId = pick(worksetRefs); op.participantId = pick(participantIds); op.beforeId = pick([...participantIds, null]); break;
    case 'workset.add': op.workset = randomWorkset(); if (rnd() < 0.4) op.beforeId = pick([...worksetIds, null]); break;
    case 'workset.rename': op.worksetId = pick(worksetRefs); op.name = pick(worksetNames); break;
    case 'workset.delete': case 'workset.makeDefault': op.worksetId = pick(worksetRefs); break;
    case 'capture.add': op.capture = {id: pick(captureIds), ts: pick([1790000000000 + i, -1, 1.5]), tzOffset: pick([120, -480, 0, null, undefined, 1200, 1.5, '60']), participantId: pick([...participantIds, null]), kind: pick(captureKinds), worksetId: pick(worksetRefs)}; break;
    case 'capture.assign': op.captureId = pick(captureIds); op.participantId = pick([...participantIds, null]); break;
    case 'capture.delete': op.captureId = pick(captureIds); break;
    case 'capture.setKind': op.captureId = pick(captureIds); op.kind = pick(captureKinds); break;
    case 'workset.setKind': op.worksetId = pick(worksetRefs); op.kind = pick(captureKinds); break;
    case 'kind.add': op.kind = rnd() < 0.1 ? pick([null, 'k1']) : randomKind(); break;
    case 'kind.update': op.kindId = pick(kindIds); op.name = pick(kindNames); op.role = pick(kindRoles); break;
    case 'kind.delete': op.kindId = pick(kindIds); break;
    case 'state.merge':
      op.state = {
        schema: pick([undefined, undefined, null, 1, SCHEMA_VERSION, SCHEMA_VERSION + 1, 0, '1', 1.5]),
        name: pick(['Local', null]),
        sport: pick(['sailing', 'running', 'generic', undefined, null, 'bogus', 7]),
        participants: [{id: 'm' + i, name: pick(names)}, {id: pick(participantIds), name: 'NED 7'}],
        kinds: pick([undefined, [], [{id: 'mk' + i, name: pick(['Protest', 'Gate']), role: pick(['split', 'marker'])}], [randomKind()]]),
        worksets: pick([undefined, [], [randomWorkset()],
          [{id: 'mw' + i, name: pick(['Finish', null, 'Gate']), ranking: ['m' + i, pick(participantIds)], captureKind: pick(['mk' + i, 'start', 'k1'])},
            {id: pick(worksetIds), name: pick(worksetNames), ranking: pick(rankings)}]]),
        captures: [{id: 'mc' + i, ts: 1790000000500, tzOffset: pick([120, null, 999]), participantId: 'm' + i, kind: pick(['mk' + i, ...captureKinds]),
          worksetId: pick(['mw' + i, ...worksetRefs])},
          {id: pick(captureIds), ts: 5, participantId: null}],
      };
      break;
  }
  return op;
}

// States stored before versioning (no `schema`) come out at the current version on both sides.
const legacy = normalizeState({boats: [{id: 'b1', name: 'GER 1'}], ranking: ['b1'], captures: [{id: 'c1', ts: 5, boatId: 'b1'}]});
if (legacy.schema !== SCHEMA_VERSION) throw new Error('normalizeState() does not set the current schema version');

const count = parseInt(process.argv[2] || '200', 10);
const cases = [];
for (let n = 0; n < count; n++) {
  const ops = Array.from({length: 40}, (_, i) => randomOp(i));
  let state = emptyState();
  const results = [];
  for (const op of ops) {
    const copy = JSON.parse(JSON.stringify(state));
    try { state = applyOp(copy, op); results.push('ok'); } catch (e) { results.push(e.message); }
  }
  cases.push({ops, expected: {state, results}});
}

const casesFile = path.join(os.tmpdir(), `reducer-parity-${process.pid}.json`);
fs.writeFileSync(casesFile, JSON.stringify(cases));
try {
  const out = execFileSync('php', [path.join(root, 'tests/reducer-parity.php'), casesFile, String(SCHEMA_VERSION)], {encoding: 'utf8'});
  process.stdout.write(out);
} catch (e) {
  process.stdout.write(e.stdout || '');
  process.exitCode = 1;
} finally {
  fs.unlinkSync(casesFile);
}

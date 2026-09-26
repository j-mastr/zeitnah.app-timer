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
const rankings = [undefined, null, [], ['b1', 'b2', 'b1', 'bX'], ['b3', 'b2'], ['not valid!'], 'b1', [7],
  [{type: 'participant', id: 'b1'}, {type: 'group', id: 'g1'}, 'b2', {type: 'group', id: 'g1'}, {type: 'group', id: 'gX'}],
  [{type: 'group', id: 'g2'}, {type: 'team', id: 'x'}], [{type: 'group', id: 'g3'}, {type: 'participant', id: 'b3'}]];
const randomWorkset = () => (rnd() < 0.1 ? pick([null, 'w1', {}]) : {
  id: pick(worksetIds), name: pick(worksetNames), number: pick([undefined, undefined, undefined, undefined, null, 3, 0, 1.5, '2']),
  ranking: pick(rankings), captureKind: pick([undefined, undefined, ...captureKinds]),
});
const ref = (id) => ({type: 'participant', id});
const groupIds = ['g1', 'g2', 'g3', 'g4', 'not valid!'];
const groupTypeIds = ['t1', 't2', 't3', 'not valid!'];
const gref = (id) => ({type: 'group', id});
const groupNames = ['Fleet A', 'fleet a', ' Gold ', 'Silver', 'Wave 1', 'Wave 2', 'Laser', '', 'q'.repeat(41), 5];
const randomRefs = () => pick([undefined, null, [], [ref(pick(participantIds))], [gref(pick(groupIds)), ref(pick(participantIds)), gref(pick(groupIds))],
  [ref(pick(participantIds)), ref(pick(participantIds)), gref(pick(groupIds)), ref('b1'), ref('b1')], [{type: 'team', id: 'x'}], 'g1', [gref('g1'), gref('g2'), gref('g3')]]);
const randomGroupType = () => (rnd() < 0.1 ? pick([null, 't1', []]) : {id: pick(groupTypeIds), name: pick(['Fleet', 'fleet', 'Class', '', 3]), exclusive: pick([undefined, null, true, false, 'yes'])});
const fieldIds = ['f1', 'f2', 'f3', 'not valid!'];
const randomField = () => (rnd() < 0.08 ? pick([null, 'f1']) : {id: pick(fieldIds.slice(0, 3)), name: pick(['Yardstick', 'yardstick', 'Club', 'Bib', '', 5]), type: pick(['number', 'number', 'text', 'text', 'choice'])});
const metaValues = [102, 99.5, 0, -3, 'KYC', ' Kieler  YC ', '', null, true, 'x'.repeat(81), [1], 1e21];
const randomMeta = () => pick([undefined, null, [], [{field: pick(fieldIds), value: pick(metaValues)}],
  [{field: 'f1', value: pick([102, 95, 110])}, {field: 'f2', value: pick(['KYC', 'NRV', 7])}], 'f1', [{value: 3}]]);
const randomRule = () => pick([undefined, null, {all: []}, 'eq',
  {all: [{field: 'f1', op: 'range', min: pick([null, 95, 100]), max: pick([null, 105, 'x'])}]},
  {all: [{field: 'f2', op: 'eq', value: pick(['KYC', '', 3, null])}]},
  {all: [{field: pick(fieldIds), op: 'in', values: pick([[], ['KYC', 'NRV'], [102, 'x'], 'KYC'])}, {field: 'f1', op: pick(['range', 'lt']), min: 1}]}]);
const randomGroup = () => (rnd() < 0.05 ? pick([null, 'g1']) : {id: pick(groupIds.slice(0, 4)), typeId: pick([undefined, null, ...groupTypeIds, 'tX']),
  name: rnd() < 0.8 ? pick(groupNames.slice(0, 7)) : pick(groupNames), members: rnd() < 0.3 ? randomRefs() : undefined,
  ...(rnd() < 0.3 ? {rule: randomRule()} : {})});
const validRefs = () => Array.from({length: 1 + Math.floor(rnd() * 4)}, () => (rnd() < 0.7 ? ref(pick(participantIds.slice(0, 5))) : gref(pick(groupIds.slice(0, 4)))));
const randomTarget = () => pick([ref(pick(participantIds)), ref(pick(participantIds)), ref(pick(participantIds)), gref(pick(groupIds)), gref(pick(groupIds)), {type: 'team', id: 'g1'},
  {id: 'b1'}, {type: 'participant', id: 'not valid!'}, {type: 'participant'}, 'b1', null, ['participant', 'b1']]);
const randomTargets = () => pick([undefined, null, [], [ref(pick(participantIds))], [gref(pick(groupIds))], [gref(pick(groupIds)), ref(pick(participantIds))], [ref(pick(participantIds)), ref(pick(participantIds)), ref('b1')],
  [randomTarget(), randomTarget()], Array.from({length: 501}, () => ref('b1')), 'b1', {type: 'participant', id: 'b1'}]);
// (Not generated: objects with keys 0…n, e.g. {0: ref}, which PHP decodes as a list.)
const types = ['race.rename', 'race.archive', 'race.setSport', 'participants.add', 'participants.add', 'participants.add', 'participant.rename', 'participant.delete',
  'capture.add', 'capture.add', 'capture.assign', 'capture.delete', 'capture.setKind',
  'capture.add', 'capture.assign', 'capture.target.add', 'capture.target.add', 'capture.target.remove',
  'groupType.add', 'groupType.add', 'groupType.update', 'groupType.delete',
  'group.add', 'group.add', 'group.add', 'group.add', 'group.add', 'group.update', 'group.delete',
  'group.members.add', 'group.members.add', 'group.members.add', 'group.members.add', 'group.members.remove', 'group.members.remove',
  'field.add', 'field.add', 'field.add', 'field.update', 'field.delete', 'participant.setMeta', 'participant.setMeta', 'participant.setMeta',
  'participant.setMeta', 'group.setRule', 'group.setRule',
  'kind.add', 'kind.add', 'kind.update', 'kind.delete', 'state.merge', 'bogus',
  'workset.add', 'workset.add', 'workset.add', 'workset.rename', 'workset.delete', 'workset.makeDefault', 'workset.setKind',
  'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.remove', 'workset.ranking.move', 'workset.ranking.move',
  'workset.ranking.add', 'workset.ranking.add', 'workset.ranking.move', 'workset.ranking.add', 'workset.ranking.move', 'workset.add'];

function randomOp(i) {
  const type = pick(types);
  const op = {type};
  switch (type) {
    case 'race.rename': op.name = pick(['Kieler Woche', null, '   ', 'y'.repeat(81)]); break;
    case 'race.setSport': op.sport = pick(['generic', 'sailing', 'running', 'swimming', 'motor', 'bogus', null]); break;
    case 'participants.add': op.participants = [{id: pick(participantIds), name: pick(names), meta: randomMeta()}, {id: pick(participantIds), name: pick(names)}]; break;
    case 'participant.rename': op.participantId = pick(participantIds); op.name = pick(names); break;
    case 'participant.delete': op.participantId = pick(participantIds); break;
    case 'workset.ranking.add': case 'workset.ranking.remove':
      op.worksetId = rnd() < 0.6 ? 'w1' : pick(worksetRefs);
      if (rnd() < 0.5) op.participantId = pick(participantIds); else op.ref = rnd() < 0.8 ? validRefs()[0] : randomTarget();
      break;
    case 'workset.ranking.move':
      op.worksetId = rnd() < 0.6 ? 'w1' : pick(worksetRefs);
      if (rnd() < 0.5) op.participantId = pick(participantIds); else op.ref = validRefs()[0];
      if (rnd() < 0.5) op.beforeId = pick([...participantIds, null]); else op.before = pick([null, randomTarget(), ...validRefs()]);
      break;
    case 'workset.add': op.workset = randomWorkset(); if (rnd() < 0.4) op.beforeId = pick([...worksetIds, null]); break;
    case 'workset.rename': op.worksetId = pick(worksetRefs); op.name = pick(worksetNames); break;
    case 'workset.delete': case 'workset.makeDefault': op.worksetId = pick(worksetRefs); break;
    case 'capture.add': op.capture = {id: pick(captureIds), ts: pick([1790000000000 + i, -1, 1.5]), tzOffset: pick([120, -480, 0, null, undefined, 1200, 1.5, '60']), participantId: pick([...participantIds, null]), kind: pick(captureKinds), worksetId: pick(worksetRefs)};
      if (rnd() < 0.6) op.capture.targets = randomTargets();
      if (rnd() < 0.4) delete op.capture.participantId;
      // A valid one with participants and groups (the picks above are correlated and rarely all valid).
      if (rnd() < 0.3) op.capture = {id: pick(captureIds), ts: 1790000000000 + i, tzOffset: 60, kind: pick(['start', 'finish', 'split']), worksetId: pick(worksetRefs.slice(0, 7)), targets: validRefs()};
      break;
    case 'capture.assign':
      op.captureId = pick(captureIds);
      if (rnd() < 0.5) op.participantId = pick([...participantIds, null]); else op.targets = randomTargets();
      break;
    case 'capture.target.add': case 'capture.target.remove': op.captureId = pick(captureIds); op.target = randomTarget(); break;
    case 'groupType.add': op.groupType = randomGroupType(); if (rnd() < 0.3) op.beforeId = pick([...groupTypeIds, null]); break;
    case 'groupType.update': op.groupTypeId = pick(groupTypeIds); op.name = pick(['Fleet', 'Class', '', 7]); op.exclusive = pick([true, false, undefined, 1]); break;
    case 'groupType.delete': op.groupTypeId = pick(groupTypeIds); break;
    case 'group.add': op.group = randomGroup(); if (rnd() < 0.3) op.beforeId = pick([...groupIds, null]); break;
    case 'group.update': op.groupId = pick(groupIds); op.name = pick(groupNames); op.typeId = pick([undefined, null, ...groupTypeIds]); break;
    case 'group.delete': op.groupId = pick(groupIds); break;
    case 'field.add': op.field = randomField(); if (rnd() < 0.3) op.beforeId = pick([...fieldIds, null]); break;
    case 'field.update': op.fieldId = pick(fieldIds); op.name = pick(['Yardstick', 'YS', '', 4]); break;
    case 'field.delete': op.fieldId = pick(fieldIds); break;
    case 'participant.setMeta': op.participantId = pick(participantIds); op.fieldId = pick(fieldIds); op.value = pick(metaValues); break;
    case 'group.setRule': op.groupId = pick(groupIds); op.rule = randomRule(); break;
    case 'group.members.add': case 'group.members.remove': op.groupId = pick(groupIds); op.refs = rnd() < 0.7 ? validRefs() : randomRefs(); break;
    case 'capture.delete': op.captureId = pick(captureIds); break;
    case 'capture.setKind': op.captureId = pick(captureIds); op.kind = pick(captureKinds); break;
    case 'workset.setKind': op.worksetId = pick(worksetRefs); op.kind = pick(captureKinds); break;
    case 'kind.add': op.kind = rnd() < 0.1 ? pick([null, 'k1']) : randomKind(); break;
    case 'kind.update': op.kindId = pick(kindIds); op.name = pick(kindNames); op.role = pick(kindRoles); break;
    case 'kind.delete': op.kindId = pick(kindIds); break;
    case 'state.merge':
      op.state = {
        schema: pick([undefined, undefined, null, 1, 2, 4, SCHEMA_VERSION, SCHEMA_VERSION, SCHEMA_VERSION + 1, 0, '1', 1.5]),
        fields: pick([undefined, [], [randomField()], [{id: 'mf' + i, name: pick(['Yardstick', 'Club']), type: 'number'}, {id: 'f2', name: 'Club', type: 'text'}]]),
        groupTypes: pick([undefined, [], [randomGroupType()], [{id: 'mt' + i, name: pick(['Fleet', 'Class'])}, {id: pick(groupTypeIds), name: 'Club'}]]),
        groups: pick([undefined, [], [randomGroup()],
          [{id: 'mg' + i, typeId: pick(['mt' + i, 't1', null]), name: pick(['Fleet A', 'Laser']), members: [ref('m' + i), gref(pick(groupIds)), gref('mh' + i)],
            rule: pick([null, {all: [{field: 'mf' + i, op: 'range', min: 100, max: null}]}])},
            {id: 'mh' + i, name: 'Wave 1', members: [gref('mg' + i), ref(pick(participantIds))]}, randomGroup()]]),
        name: pick(['Local', null]),
        sport: pick(['sailing', 'running', 'generic', undefined, null, 'bogus', 7]),
        participants: [{id: 'm' + i, name: pick(names), meta: pick([undefined, [{field: 'mf' + i, value: 101}, {field: 'f2', value: 'KYC'}], randomMeta()])},
          {id: pick(participantIds), name: 'NED 7', meta: [{field: pick(['mf' + i, 'f1']), value: pick([98, 'x'])}]}],
        kinds: pick([undefined, [], [{id: 'mk' + i, name: pick(['Protest', 'Gate']), role: pick(['split', 'marker'])}], [randomKind()]]),
        worksets: pick([undefined, [], [randomWorkset()],
          [{id: 'mw' + i, name: pick(['Finish', null, 'Gate']), ranking: ['m' + i, pick(participantIds)], captureKind: pick(['mk' + i, 'start', 'k1'])},
            {id: pick(worksetIds), name: pick(worksetNames), ranking: pick(rankings)}]]),
        captures: [{id: 'mc' + i, ts: 1790000000500, tzOffset: pick([120, null, 999]), kind: pick(['mk' + i, ...captureKinds]),
          worksetId: pick(['mw' + i, ...worksetRefs]), ...pick([{participantId: 'm' + i}, {targets: [ref('m' + i), ref(pick(participantIds)), ref('zz'), gref('mg' + i), gref(pick(groupIds))]}, {targets: randomTargets()}])},
          {id: pick(captureIds), ts: 5, participantId: null}],
      };
      break;
  }
  return op;
}

// States stored before versioning (no `schema`) come out at the current version on both sides.
const legacy = normalizeState({boats: [{id: 'b1', name: 'GER 1'}], ranking: ['b1'], captures: [{id: 'c1', ts: 5, boatId: 'b1'}]});
if (legacy.schema !== SCHEMA_VERSION) throw new Error('normalizeState() does not set the current schema version');
if (JSON.stringify(legacy.captures[0].targets) !== JSON.stringify([ref('b1')])) throw new Error('normalizeState() does not migrate a capture\'s participant');

const count = parseInt(process.argv[2] || '200', 10);
// Fixed sequences for what random ones rarely reach: a long mixed ranking of participants and
// groups, reordered, recorded against, merged.
const P = (id) => ({type: 'participant', id}), Q = (id) => ({type: 'group', id});
const fixed = [[
  {type: 'participants.add', participants: ['p1', 'p2', 'p3', 'p4'].map((id) => ({id, name: id.toUpperCase()}))},
  {type: 'group.add', group: {id: 'ga', name: 'Fleet A', members: [P('p1'), P('p2')]}},
  {type: 'group.add', group: {id: 'gb', name: 'Fleet B', members: [P('p3')]}},
  {type: 'workset.add', workset: {id: 'w1'}},
  {type: 'workset.ranking.add', worksetId: 'w1', ref: Q('ga')},
  {type: 'workset.ranking.add', worksetId: 'w1', participantId: 'p4'},
  {type: 'workset.ranking.add', worksetId: 'w1', ref: Q('gb')},
  {type: 'workset.ranking.add', worksetId: 'w1', ref: P('p1')},
  {type: 'workset.ranking.add', worksetId: 'w1', ref: Q('ga')},
  {type: 'workset.ranking.move', worksetId: 'w1', ref: Q('gb'), before: Q('ga')},
  {type: 'workset.ranking.move', worksetId: 'w1', participantId: 'p4', beforeId: 'p1'},
  {type: 'workset.ranking.move', worksetId: 'w1', ref: P('p1'), before: null},
  {type: 'workset.ranking.move', worksetId: 'w1', ref: Q('ga'), before: Q('gX')},
  {type: 'capture.add', capture: {id: 'c1', ts: 1790000000000, kind: 'start', worksetId: 'w1', targets: [Q('gb')]}},
  {type: 'workset.add', workset: {id: 'w2', ranking: [Q('ga'), 'p2', P('p3'), Q('gone'), 'p2']}},
  {type: 'group.delete', groupId: 'ga'},
  {type: 'participant.delete', participantId: 'p3'},
  {type: 'state.merge', state: {schema: 3, participants: [{id: 'm1', name: 'P1'}, {id: 'm2', name: 'M2'}],
    groups: [{id: 'mg', name: 'Fleet C', members: [P('m2')]}],
    worksets: [{id: 'w1', ranking: [P('m1'), Q('mg'), P('m2')]}, {id: 'mw', name: 'Gate', ranking: ['m1']}]}},
  {type: 'state.merge', state: {schema: 2, participants: [{id: 'x1', name: 'X1'}], worksets: [{id: 'w2', ranking: ['x1']}]}},
  {type: 'workset.ranking.remove', worksetId: 'w1', ref: Q('mg')},
  {type: 'workset.ranking.add', worksetId: 'w1', ref: {type: 'group'}},
], [
  {type: 'field.add', field: {id: 'ys', name: 'Yardstick', type: 'number'}},
  {type: 'field.add', field: {id: 'club', name: 'Club', type: 'text'}, beforeId: 'ys'},
  {type: 'participants.add', participants: [
    {id: 'p1', name: 'GER 1', meta: [{field: 'ys', value: 102}, {field: 'club', value: ' Kieler  YC '}, {field: 'zz', value: 1}]},
    {id: 'p2', name: 'NED 7', meta: [{field: 'ys', value: 'fast'}, {field: 'club', value: 7}]}]},
  {type: 'participant.setMeta', participantId: 'p2', fieldId: 'ys', value: 99.5},
  {type: 'participant.setMeta', participantId: 'p1', fieldId: 'club', value: '   '},
  {type: 'participant.setMeta', participantId: 'p1', fieldId: 'club', value: false},
  {type: 'group.add', group: {id: 'g', name: 'Fast', rule: {all: [{field: 'ys', op: 'range', min: null, max: 100}]}}},
  {type: 'group.setRule', groupId: 'g', rule: {all: [{field: 'ys', op: 'in', values: [99.5, 102]}, {field: 'club', op: 'eq', value: 'KYC'}]}},
  {type: 'field.update', fieldId: 'club', name: 'Verein'},
  {type: 'field.delete', fieldId: 'ys'},
  {type: 'state.merge', state: {schema: 5, fields: [{id: 'x', name: 'verein', type: 'text'}, {id: 'b', name: 'Bib', type: 'number'}],
    participants: [{id: 'q', name: 'ger 1', meta: [{field: 'x', value: 'NRV'}, {field: 'b', value: 12}]}, {id: 'r', name: 'FRA 3', meta: [{field: 'x', value: 5}]}],
    groups: [{id: 'g', name: 'Fast', rule: null}, {id: 'h', name: 'Bibs', rule: {all: [{field: 'b', op: 'range', min: 10}]}}]}},
  {type: 'state.merge', state: {schema: 4, participants: [{id: 's', name: 'S'}], groups: [{id: 'k', name: 'Old', members: []}]}},
]];
const cases = [];
for (let n = 0; n < count + fixed.length; n++) {
  const ops = n < fixed.length ? fixed[n] : Array.from({length: 40}, (_, i) => randomOp(i));
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

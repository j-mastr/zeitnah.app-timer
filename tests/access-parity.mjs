#!/usr/bin/env node
// Checks that the access rules in frontend/index.html (the "Access rules" section) and the
// PHP implementation (App\Access\Permissions) agree: the permission each operation needs,
// whether a set of rules allows it, and whether rules are restricted to worksets.
//
// Usage: node tests/access-parity.mjs [cases=500]   (requires `composer install`)
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = fs.readFileSync(path.join(root, 'frontend/index.html'), 'utf8');
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].pop()[1];
const start = script.indexOf('// Access rules');
const end = script.indexOf('// Undo / redo (per device, in memory)');
if (start < 0 || end < 0) throw new Error('Could not locate the access rules in frontend/index.html');
const {rulesAllow, restrictedToWorksets, operationPath} = new Function(
  script.slice(start, script.lastIndexOf('\n', end)) + '\n; return {rulesAllow, restrictedToWorksets, operationPath};')();

let seed = 11;
const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
const pick = (list) => list[Math.floor(rnd() * list.length)];

const worksets = ['w1', 'w2', 'w3'];
const ruleParts = [
  '*', 'race.*', 'race.view', 'race.rename', 'race.merge', 'participant.*', 'participant.add', 'kind.*', 'kind.view',
  'workset.*', 'workset.add', 'workset.view', 'workset.capture.view', 'workset.capture.*', 'workset.ranking.*',
  'workset[w1].*', 'workset[w1].view', 'workset[w1].ranking.*', 'workset[w1].capture.*', 'workset[w2].view', 'workset[w2].setKind',
  'workset[w2].ranking.add', 'capture.*', 'capture.view', 'capture.add', '*.view', 'workset.*.add', 'access.view',
  'revoke:race.setSport', 'revoke:workset[w1].capture.delete', 'revoke:*', 'revoke:workset.delete',
  'bad rule', 'workset[w 1].view', 'race..view', '', 'race[r1].*', 'workset[].view', 7,
];
const randomRules = () => Array.from({length: 1 + Math.floor(rnd() * 4)}, () => pick(ruleParts));

const state = {captures: [
  {id: 'c1', worksetId: 'w1'}, {id: 'c2', worksetId: 'w2'}, {id: 'c3', worksetId: null},
]};
const types = ['race.rename', 'race.setSport', 'race.archive', 'participants.add', 'participant.rename', 'participant.delete',
  'kind.add', 'kind.update', 'kind.delete', 'state.merge', 'workset.add', 'workset.rename', 'workset.delete', 'workset.makeDefault',
  'workset.setKind', 'workset.ranking.add', 'workset.ranking.remove', 'workset.ranking.move',
  'capture.add', 'capture.assign', 'capture.delete', 'capture.setKind', 'capture.target.add', 'capture.target.remove',
  'groupType.add', 'groupType.update', 'groupType.delete', 'group.add', 'group.update', 'group.delete', 'group.members.add', 'group.members.remove', 'bogus', 7];
function randomOp() {
  const op = {type: pick(types)};
  if (rnd() < 0.9) op.worksetId = pick([...worksets, null, 5, 'x y']);
  if (rnd() < 0.9) op.captureId = pick(['c1', 'c2', 'c3', 'c9']);
  if (rnd() < 0.9) op.capture = pick([null, [], 'c', {}, {worksetId: pick([...worksets, null, 3])}]);
  return op;
}

const cases = [];
const count = parseInt(process.argv[2] || '500', 10);
for (let n = 0; n < count; n++) {
  const rules = randomRules();
  const op = randomOp();
  const opPath = operationPath(state, op);
  const viewPath = [['workset', pick([...worksets, ''])], ['view', null]];
  cases.push({rules, op, expected: {
    path: opPath, allowed: opPath === null ? null : rulesAllow(rules, opPath),
    view: rulesAllow(rules, viewPath), viewPath, restricted: restrictedToWorksets(rules),
  }});
}

const casesFile = path.join(os.tmpdir(), `access-parity-${process.pid}.json`);
fs.writeFileSync(casesFile, JSON.stringify({state, cases}));
try {
  process.stdout.write(execFileSync('php', [path.join(root, 'tests/access-parity.php'), casesFile], {encoding: 'utf8'}));
} catch (e) {
  process.stdout.write(e.stdout || '');
  process.exitCode = 1;
} finally {
  fs.unlinkSync(casesFile);
}

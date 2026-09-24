#!/usr/bin/env node
// Checks that the JavaScript reducer in frontend/index.html (applyOp) and the PHP
// reducer (App\Regatta\OperationReducer) produce identical states and identical
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
const {applyOp, emptyState} = new Function(script.slice(start, end) + '; return {applyOp, emptyState};')();

let seed = 42;
const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
const pick = (list) => list[Math.floor(rnd() * list.length)];

const boatIds = ['b1', 'b2', 'b3', 'b4', 'b5', 'bX', 'not valid!'];
const captureIds = ['c1', 'c2', 'c3', 'c4'];
const names = ['GER 1', 'ger 1', '  NED  7 ', 'Ö-Boot', '', 'x'.repeat(61), 'FRA 12', 42, '⛵ Emoji'];
const types = ['regatta.rename', 'regatta.archive', 'boats.add', 'boat.rename', 'boat.delete', 'ranking.add',
  'ranking.remove', 'ranking.move', 'capture.add', 'capture.assign', 'capture.delete', 'state.merge', 'bogus'];

function randomOp(i) {
  const type = pick(types);
  const op = {type};
  switch (type) {
    case 'regatta.rename': op.name = pick(['Kieler Woche', null, '   ', 'y'.repeat(81)]); break;
    case 'boats.add': op.boats = [{id: pick(boatIds), name: pick(names)}, {id: pick(boatIds), name: pick(names)}]; break;
    case 'boat.rename': op.boatId = pick(boatIds); op.name = pick(names); break;
    case 'boat.delete': case 'ranking.add': case 'ranking.remove': op.boatId = pick(boatIds); break;
    case 'ranking.move': op.boatId = pick(boatIds); op.beforeId = pick([...boatIds, null]); break;
    case 'capture.add': op.capture = {id: pick(captureIds), ts: pick([1790000000000 + i, -1, 1.5]), boatId: pick([...boatIds, null])}; break;
    case 'capture.assign': op.captureId = pick(captureIds); op.boatId = pick([...boatIds, null]); break;
    case 'capture.delete': op.captureId = pick(captureIds); break;
    case 'state.merge':
      op.state = {
        name: pick(['Local', null]),
        boats: [{id: 'm' + i, name: pick(names)}, {id: pick(boatIds), name: 'NED 7'}],
        ranking: ['m' + i, pick(boatIds)],
        captures: [{id: 'mc' + i, ts: 1790000000500, boatId: 'm' + i}, {id: pick(captureIds), ts: 5, boatId: null}],
      };
      break;
  }
  return op;
}

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
  const out = execFileSync('php', [path.join(root, 'tests/reducer-parity.php'), casesFile], {encoding: 'utf8'});
  process.stdout.write(out);
} catch (e) {
  process.stdout.write(e.stdout || '');
  process.exitCode = 1;
} finally {
  fs.unlinkSync(casesFile);
}

#!/usr/bin/env node
// Checks the UI text sets in frontend/index.html:
//  - every set (common and each sport) has the same keys in every language,
//  - every sport set has the same keys as the others,
//  - every key used in markup (data-i18n*) or code (t('...')) resolves for every sport
//    and language. Keys referenced indirectly through variables are listed in DYNAMIC_KEYS.
//
// Usage: node tests/text-keys.mjs
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = fs.readFileSync(path.join(root, 'frontend/index.html'), 'utf8');
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].pop()[1];
const start = script.indexOf('const SPORTS');
const end = script.indexOf('// Browser storage');
const {TEXTS} = new Function(script.slice(start, script.lastIndexOf('\n', end)) + '\n; return {TEXTS};')();

const DYNAMIC_KEYS = [
  'status.local', 'status.connecting', 'status.online', 'status.offline',
  'action.drag', 'action.up', 'action.down', 'action.unsort', 'action.sort', 'action.rename', 'action.delete',
  'lock.offline', 'lock.archived', 'settings.transportWs', 'settings.transportPoll',
  'sport.generic', 'sport.sailing', 'sport.running', 'sport.swimming', 'sport.motor',
  'kind.start', 'kind.split', 'kind.finish', 'kinds.role.split', 'kinds.role.marker', 'worksets.deleted', 'worksets.other',
  ...['place', 'timestamp', 'time', 'participant', 'kind', 'elapsed', 'station'].map((col) => `export.col.${col}`),
];
const used = new Set([
  ...[...script.matchAll(/\bt\('([\w.]+)'/g)].map((m) => m[1]),
  ...[...html.matchAll(/data-i18n(?:-placeholder|-title)?="([\w.]+)"/g)].map((m) => m[1]),
  ...DYNAMIC_KEYS,
]);

const problems = [];
const languages = Object.keys(TEXTS.common);
const sports = Object.keys(TEXTS).filter((set) => set !== 'common');
const keysOf = (set, lang) => Object.keys(TEXTS[set][lang] || {});

for (const set of Object.keys(TEXTS)) {
  const reference = new Set(keysOf(set, languages[0]));
  for (const lang of languages) {
    const keys = new Set(keysOf(set, lang));
    for (const k of reference) if (!keys.has(k)) problems.push(`${set}.${lang} lacks "${k}"`);
    for (const k of keys) if (!reference.has(k)) problems.push(`${set}.${lang} has extra "${k}"`);
  }
}
for (const sport of sports.slice(1)) {
  const a = new Set(keysOf(sports[0], languages[0])), b = new Set(keysOf(sport, languages[0]));
  for (const k of a) if (!b.has(k)) problems.push(`sport set "${sport}" lacks "${k}"`);
  for (const k of b) if (!a.has(k)) problems.push(`sport set "${sport}" has extra "${k}"`);
}
for (const sport of sports) {
  for (const lang of languages) {
    for (const k of used) {
      if (TEXTS[sport][lang][k] === undefined && TEXTS.common[lang][k] === undefined) {
        problems.push(`"${k}" is used but missing for ${sport}/${lang}`);
      }
    }
  }
}
const unused = [...new Set(languages.flatMap((l) => [...keysOf('common', l), ...sports.flatMap((s) => keysOf(s, l))]))]
  .filter((k) => !used.has(k));
for (const k of unused) problems.push(`"${k}" is defined but never used`);

if (problems.length) {
  console.log(problems.join('\n'));
  process.exitCode = 1;
} else {
  console.log(`Text sets OK: ${sports.join(', ')} × ${languages.join(', ')}, ${used.size} keys in use.`);
}

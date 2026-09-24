#!/usr/bin/env node
// Browser smoke test for the most important flows with two clients.
//
// Needs a running installation (web server + app:websocket-server) and Playwright:
//   npm install && npx playwright install chromium
//   BASE_URL=http://127.0.0.1:8000/ node tests/e2e/sync-smoke.mjs
import {chromium} from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000/';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const waitStatus = (page, prefix, timeout = 10000) =>
  page.waitForFunction((p) => document.getElementById('statusText').textContent.startsWith(p), prefix, {timeout});
const step = (name) => console.log('✓', name);

const browser = await chromium.launch();
try {
  const ctxA = await browser.newContext({locale: 'de-DE', viewport: {width: 1100, height: 900}});
  const a = await ctxA.newPage();
  a.on('pageerror', (e) => { throw e; });
  await a.goto(BASE);
  await a.evaluate(() => localStorage.clear());
  await a.reload();
  assert.equal(await a.textContent('#statusText'), 'Lokal im Browser');

  for (const name of ['GER 123', 'NED 7', 'FRA 44']) {
    await a.fill('#participantInput', name);
    await a.click('#addParticipantBtn');
  }
  await a.click('#captureBtn');
  assert.equal(await a.textContent('#capCount'), '1');
  step('local mode: participants and a capture');

  await a.click('#settingsBtn');
  await a.click('#createBtn');
  await a.waitForSelector('#dialog:not([hidden])');
  await a.click('#dialogOk');
  await waitStatus(a, 'Server verbunden');
  await sleep(500);
  const code = await a.inputValue('#srvCode');
  assert.match(await a.evaluate(() => location.hash), new RegExp('#r=' + code));
  assert.equal(await a.locator('#participantList li').count(), 3);
  assert.equal(await a.evaluate(() => JSON.parse(localStorage.getItem('race-timer.local')).participants.length), 0);
  await a.click('#drawerClose');
  step(`new race ${code} initialised with local data`);

  const ctxB = await browser.newContext({locale: 'en-US', viewport: {width: 390, height: 844}});
  const b = await ctxB.newPage();
  b.on('pageerror', (e) => { throw e; });
  await b.goto(BASE + '#r=' + code.toLowerCase());
  await waitStatus(b, 'Connected to server');
  assert.equal(await b.locator('#participantList li').count(), 3);
  step('second client joins via #r= link (English UI)');

  await b.fill('#rankSearch', 'gr123');
  await b.keyboard.press('Enter');
  await b.fill('#rankSearch', 'fra');
  await b.keyboard.press('Enter');
  await sleep(800);
  assert.deepEqual(await a.locator('#sortedList .nm').allTextContents(), ['GER 123', 'FRA 44']);
  step('fuzzy quick-add and sorted list sync');

  await a.click('h1');
  await a.keyboard.press('Space');
  await sleep(800);
  assert.equal(await b.textContent('#capCount'), '2');
  assert.deepEqual(await b.locator('#sortedList .nm').allTextContents(), ['FRA 44']);
  step('space bar assigns the first sorted participant on all clients');

  await b.click('#renameRaceBtn');
  await b.fill('#raceTitle input', 'Smoke Test Race');
  await b.keyboard.press('Enter');
  await b.click('#settingsBtn');
  await b.click('#archiveBtn');
  await b.click('#dialogOk');
  await sleep(1000);
  assert.equal(await a.textContent('#raceTitle'), 'Smoke Test Race');
  assert.equal(await a.isVisible('#archivedBanner'), true);
  await a.keyboard.press('Space');
  await sleep(300);
  assert.equal(await a.textContent('#capCount'), '2');
  step('rename and archive; archived race is read-only');

  await a.goto(BASE);
  await waitStatus(a, 'Archiviert');
  step('reload resumes the stored connection');

  console.log('\nAll smoke tests passed.');
} finally {
  await browser.close();
}

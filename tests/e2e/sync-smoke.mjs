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
  // Uploading to the server keeps the local data in this browser.
  assert.equal(await a.evaluate(() => JSON.parse(localStorage.getItem('zeitnah.local')).participants.length), 3);
  await a.click('#drawerClose');
  step(`new race ${code} initialised with local data`);

  const ctxB = await browser.newContext({locale: 'en-US', viewport: {width: 390, height: 844}});
  const b = await ctxB.newPage();
  b.on('pageerror', (e) => { throw e; });
  await b.goto(BASE + '#r=' + code.toLowerCase());
  await waitStatus(b, 'Connected to server');
  assert.equal(await b.locator('#participantList li').count(), 3);
  step('second client joins via #r= link (English UI)');

  // No station yet, so no approaching list: F creates the first station and opens its search.
  assert.equal(await b.isVisible('#rankSearch'), false);
  await b.click('h1');
  await b.keyboard.press('f');
  await b.fill('#rankSearch', 'gr123');
  await b.keyboard.press('Enter');
  await b.fill('#rankSearch', 'fra');
  await b.keyboard.press('Enter');
  await sleep(800);
  // The other device joins the first station as soon as it exists.
  assert.deepEqual(await a.locator('#sortedList .nm').allTextContents(), ['GER 123', 'FRA 44']);
  step('F creates the first station; fuzzy quick-add and sorted list sync');

  await a.click('h1');
  await a.keyboard.press('Space');
  await sleep(800);
  assert.equal(await b.textContent('#capCount'), '2');
  assert.deepEqual(await b.locator('#sortedList .nm').allTextContents(), ['FRA 44']);
  step('space bar assigns the first sorted participant on all clients');

  // Capture kinds: custom kinds and the selected kind are race data; a one-shot kind stays on its device.
  await b.click('#settingsBtn');
  await b.fill('#kindNameInput', 'Protest');
  await b.click('#addKindBtn');
  await b.click('#drawerClose');
  await a.waitForSelector('#kindSeg:not([hidden]) button:has-text("Protest")');
  await a.click('#kindSeg button[data-kind="start"]');
  await b.waitForFunction(() => document.getElementById('captureBtn').textContent === 'RECORD START');
  assert.equal(await b.textContent('#sortedTitle'), 'Approaching the start');
  await a.click('#kindSeg button:has-text("Protest")', {button: 'right'});
  assert.equal(await a.textContent('#captureBtn'), 'PROTEST ERFASSEN');
  assert.equal(await b.textContent('#captureBtn'), 'RECORD START');
  await a.click('h1');
  await a.keyboard.press('Space');
  await sleep(800);
  assert.equal(await a.textContent('#captureBtn'), 'START ERFASSEN');
  assert.equal(await b.textContent('#capCount'), '3');
  // A protest is a marker: the participant keeps its place in the approaching list.
  assert.deepEqual(await b.locator('#sortedList .nm').allTextContents(), ['FRA 44']);
  await b.selectOption('#capList .cap-row >> nth=0 >> select.kind-select', 'finish');
  await a.click('#kindSeg button[data-kind="finish"]');
  await sleep(800);
  assert.equal(await a.inputValue('#capList .cap-row >> nth=0 >> select.kind-select'), 'finish');
  assert.equal(await b.textContent('#captureBtn'), 'RECORD TIME');
  step('capture kinds: custom kind, synced selection, local one-shot, retyping');

  // Stations: a second one has its own approaching list and kind.
  await b.click('#settingsBtn');
  await b.click('#addWorksetBtn');
  await b.waitForSelector('#worksetList .ws-row >> nth=2');
  await b.click('#worksetList .ws-row >> nth=1 >> .ws-pick');
  await b.click('#drawerClose');
  assert.equal(await b.locator('#sortedList li').count(), 0);
  assert.equal(await b.textContent('#sortedStation'), '· Station 2');
  await b.click('h1');
  await b.keyboard.press('f');
  await b.fill('#rankSearch', 'ned');
  await b.keyboard.press('Enter');
  await b.click('#kindSeg button[data-kind="start"]');
  await sleep(800);
  assert.deepEqual(await a.locator('#sortedList .nm').allTextContents(), ['FRA 44']);
  assert.deepEqual(await b.locator('#sortedList .nm').allTextContents(), ['NED 7']);
  assert.equal(await a.textContent('#captureBtn'), 'ZEIT ERFASSEN');
  assert.equal(await b.textContent('#captureBtn'), 'RECORD START');
  // Deleted on another device: a notice offers the remaining stations.
  await a.click('#settingsBtn');
  await a.click('#worksetList .ws-row >> nth=1 >> button:has-text("✕")');
  await a.click('#dialogOk');
  await a.click('#drawerClose');
  await b.waitForSelector('#worksetNotice:not([hidden])');
  assert.equal(await b.textContent('#worksetNoticeText'), '“Station 2” was deleted – this device has no station now.');
  assert.equal(await b.isVisible('.sorted-panel'), false);
  await b.click('#worksetNoticePicks button');
  assert.deepEqual(await b.locator('#sortedList .nm').allTextContents(), ['FRA 44']);
  assert.equal(await b.isVisible('#worksetNotice'), false);
  step('stations: own list and kind; deleted elsewhere, a notice switches to another');

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
  assert.equal(await a.textContent('#capCount'), '3');
  step('rename and archive; archived race is read-only');

  await a.goto(BASE);
  await waitStatus(a, 'Archiviert');
  step('reload resumes the stored connection');

  console.log('\nAll smoke tests passed.');
} finally {
  await browser.close();
}

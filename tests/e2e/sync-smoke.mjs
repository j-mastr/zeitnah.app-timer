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
  // Any assigned capture can take further participants ("+"); an unassigned one can't yet.
  assert.equal(await b.locator('#capList .cap-row >> nth=0 >> select.add-target').count(), 1);
  assert.equal(await b.locator('#capList .cap-row >> nth=1 >> select.add-target').count(), 0);
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
  // A marker can concern several participants: "+" adds one, the chips' ✕ removes one.
  const protest = '#capList .cap-row >> nth=0';
  await b.selectOption(`${protest} >> select.add-target`, {label: 'NED 7'});
  await a.waitForFunction(() => document.querySelectorAll('#capList .cap-row:first-child .target-chip').length === 2);
  assert.deepEqual(await a.locator(`${protest} >> .target-chip`).allTextContents(), ['FRA 44✕', 'NED 7✕']);
  await a.click(`${protest} >> .target-chip:has-text("FRA 44") >> button`);
  await b.waitForFunction(() => !document.querySelector('#capList .cap-row:first-child .target-chip'));
  assert.equal(await b.locator(`${protest} >> select.assign:not(.kind-select):not(.add-target) >> option:checked`).textContent(), 'NED 7');
  step('a marker concerns several participants; adding and removing them syncs');
  await b.selectOption('#capList .cap-row >> nth=0 >> select.kind-select', 'finish');
  await a.click('#kindSeg button[data-kind="finish"]');
  await sleep(800);
  assert.equal(await a.inputValue('#capList .cap-row >> nth=0 >> select.kind-select'), 'finish');
  assert.equal(await b.textContent('#captureBtn'), 'RECORD TIME');
  step('capture kinds: custom kind, synced selection, local one-shot, retyping');

  // Groups: a type with a group, members chosen in a dialog; a start for the group is its members' start.
  await a.click('#groupsBtn');
  await a.fill('#groupTypeInput', 'Flotte');
  await a.click('#addGroupTypeBtn');
  await a.click('#groupTypeList .group-type >> text=+ Gruppe hinzufügen');
  await a.keyboard.press('Control+A');
  await a.keyboard.type('Flotte A');
  await a.keyboard.press('Enter');
  await a.click('#groupTypeList .group-row .link-btn');
  await a.waitForSelector('#membersDialog:not([hidden])');
  await a.click('#membersList .member-opt:has-text("GER 123")');
  await a.click('#membersList .member-opt:has-text("NED 7")');
  await a.click('#membersOk');
  assert.equal(await a.textContent('#groupTypeList .group-row .link-btn'), '2 Mitglieder');
  // A second group with GER 123, then "one per member": allowed, but flagged.
  await a.click('#groupTypeList .group-type >> text=+ Gruppe hinzufügen');
  await a.keyboard.press('Enter');
  await a.click('#groupTypeList .group-row >> nth=1 >> .link-btn');
  await a.click('#membersList .member-opt:has-text("GER 123")');
  await a.click('#membersOk');
  await a.check('#groupTypeList .excl input');
  assert.equal(await a.textContent('#groupTypeList .group-clash'), '⚠ 1 Mitglied in mehreren Gruppen dieser Art');
  await a.click('#groupTypeList .group-row >> nth=1 >> button:has-text("✕")');
  await a.click('#dialogOk');
  await a.waitForFunction(() => !document.querySelector('#groupTypeList .group-clash'));
  await a.click('#drawerClose');
  await b.waitForFunction(() => document.querySelectorAll('#participantList .group-tag').length === 2);
  // The fleet queued for its start: the quick search offers the group, its number key records
  // its start (the start of all of its members), and it leaves the approaching list.
  await a.click('#kindSeg button[data-kind="start"]');
  await a.click('h1');
  await a.keyboard.press('f');
  await a.fill('#rankSearch', 'flotte a');
  await a.keyboard.press('Enter');
  await a.click('h1');
  await b.waitForSelector('#sortedList li.is-group');
  const position = await a.locator('#sortedList li').evaluateAll((rows) => rows.findIndex((r) => r.classList.contains('is-group')) + 1);
  assert.ok(position > 0);
  await a.keyboard.press(String(position));
  await b.waitForFunction(() => [...document.querySelectorAll('#capList .cap-row:first-child select.assign option:checked')].some(o => o.textContent === 'Flotte A'));
  assert.equal(await b.locator('#sortedList li.is-group').count(), 0);
  await a.click('#kindSeg button[data-kind="finish"]');
  await sleep(300);
  await a.dblclick('#participantList li:has-text("NED 7") .nm');
  await b.locator('#participantList li:has-text("NED 7") .meta', {hasText: 'Elapsed'}).waitFor({timeout: 5000});
  await b.selectOption('#groupFilter', {label: 'Flotte A'});
  assert.equal(await b.locator('#participantList li').count(), 2);
  await b.selectOption('#groupFilter', '');
  step('groups: members chosen in a dialog; a ranked group gets its start, which gives its members an elapsed time');

  // Stations: a second one has its own approaching list and kind.
  await b.click('#settingsBtn');
  await b.click('#addWorksetBtn');
  await b.waitForSelector('#worksetList .ws-row >> nth=2');
  await b.click('#worksetList .ws-row >> nth=1 >> .ws-pick');
  await b.click('#drawerClose');
  assert.equal(await b.locator('#sortedList li').count(), 0);
  // With two stations the station pill names this device's station.
  assert.equal(await b.textContent('#stationText'), 'Station 2');
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
  // One station left, but this device explicitly has none: the pill says so.
  assert.equal(await b.textContent('#stationText'), 'No station');
  await b.click('#worksetNoticePicks button');
  assert.equal(await b.isVisible('#stationPill'), false);
  assert.deepEqual(await b.locator('#sortedList .nm').allTextContents(), ['FRA 44']);
  assert.equal(await b.isVisible('#worksetNotice'), false);
  step('stations: own list and kind; deleted elsewhere, a notice switches to another');

  // Station codes: a device joining with one works on that station only.
  await a.click('#settingsBtn');
  const stationCode = (await a.textContent('#worksetList .ws-row >> nth=0 >> .ws-code')).replace(/^Code /, '');
  await a.click('#addWorksetBtn');   // a second station, out of the station code's sight
  await a.waitForSelector('#worksetList .ws-row >> nth=2');
  await a.click('#drawerClose');
  const ctxC = await browser.newContext({locale: 'en-US', viewport: {width: 1100, height: 900}});
  const c = await ctxC.newPage();
  c.on('pageerror', (e) => { throw e; });
  await c.goto(BASE + '#r=' + stationCode);
  await waitStatus(c, 'Connected to server');
  assert.match(await c.textContent('#statusText'), new RegExp(stationCode));
  // Bound to its station, it names it in the station pill (A does so because it sees two).
  assert.equal(await c.textContent('#stationText'), 'Station 1');
  assert.equal(await a.textContent('#stationText'), 'Station 1');
  assert.deepEqual(await c.locator('#sortedList .nm').allTextContents(), ['FRA 44']);
  for (const sel of ['#renameRaceBtn', '#importBtn', '#participantInput']) assert.equal(await c.isVisible(sel), false, `${sel} is hidden`);
  assert.equal(await c.locator('#participantList button[title="Rename"]').count(), 0);
  await c.click('#settingsBtn');
  assert.equal(await c.locator('#worksetList .ws-row').count(), 2, 'its station and "No station"');
  for (const sel of ['#sportSelect', '#kindsSection', '#groupsSection', '#addWorksetBtn', '#archiveBtn', '#mergeBtn']) assert.equal(await c.isVisible(sel), false, `${sel} is hidden`);
  await c.click('#drawerClose');
  await c.click('h1');
  await c.keyboard.press('Space');
  await sleep(800);
  assert.equal(await a.textContent('#capCount'), '6');
  assert.equal(await c.textContent('#capCount'), '6');
  // Its own capture can be changed, one without a station (the very first) can't.
  assert.equal(await c.isDisabled('#capList .cap-row >> nth=0 >> select.assign:not(.kind-select)'), false);
  assert.equal(await c.isDisabled('#capList .cap-row >> nth=-1 >> select.assign:not(.kind-select)'), true);
  step('a station code: its station only, nothing to manage, foreign times read-only');

  // Deleting its station revokes the code: the device is back on its local data.
  await a.click('#settingsBtn');
  await a.click('#worksetList .ws-row >> nth=0 >> button:has-text("✕")');
  await a.click('#dialogOk');
  await a.click('#drawerClose');
  await waitStatus(c, 'Stored in this browser');
  await c.click('#settingsBtn');
  assert.equal(await c.textContent('#recentList .recent-item >> nth=0 >> .nm'), stationCode + ' (Station 1)');
  await c.close();
  step('deleting the station revokes its code; the race stays in the recent list');

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
  assert.equal(await a.textContent('#capCount'), '6');
  step('rename and archive; archived race is read-only');

  await a.goto(BASE);
  await waitStatus(a, 'Archiviert');
  step('reload resumes the stored connection');

  console.log('\nAll smoke tests passed.');
} finally {
  await browser.close();
}

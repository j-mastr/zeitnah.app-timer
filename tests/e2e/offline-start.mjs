#!/usr/bin/env node
// Checks the progressive web app: after the first visit the page starts without the
// server, keeps working, and syncs buffered captures once the server is back.
//
// Starts its own PHP development server (port 8123) and stops it during the test, so
// run it from the project root after `composer install && php bin/console app:install`
// and `npm install && npx playwright install chromium`:
//   node tests/e2e/offline-start.mjs
import {chromium} from 'playwright';
import {spawn} from 'node:child_process';
import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8123;
const BASE = `http://127.0.0.1:${PORT}/`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const step = (name) => console.log('✓', name);

let server = null;
async function startServer() {
  server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', 'public'], {
    cwd: root, stdio: 'ignore', env: {...process.env, PHP_CLI_SERVER_WORKERS: '4'},
  });
  for (let i = 0; i < 50; i++) {
    try { if ((await fetch(BASE + 'api/config')).ok) return; } catch (e) {}
    await sleep(100);
  }
  throw new Error('PHP server did not start');
}
async function stopServer() {
  if (!server) return;
  const exited = new Promise((r) => server.once('exit', r));
  server.kill('SIGTERM');
  await exited;
  server = null;
  const stillUp = await fetch(BASE).then(() => true, () => false);
  assert.equal(stillUp, false, 'server must be unreachable after stopping it');
}

const browser = await chromium.launch();
try {
  await startServer();
  const manifest = await (await fetch(BASE + 'manifest.webmanifest')).json();
  assert.equal(manifest.display, 'standalone');
  for (const icon of manifest.icons) assert.equal((await fetch(new URL(icon.src, BASE))).status, 200, icon.src);
  step('manifest and icons are served');

  const context = await browser.newContext({locale: 'de-DE', viewport: {width: 1000, height: 800}});
  const page = await context.newPage();
  page.on('pageerror', (e) => { throw e; });
  await page.goto(BASE);
  await page.evaluate(() => localStorage.clear());
  await page.evaluate(() => navigator.serviceWorker.ready);
  await page.reload();
  assert.ok(await page.evaluate(() => !!navigator.serviceWorker.controller), 'page is controlled by the service worker');
  step('service worker installed and controlling the page');

  // Local mode offline start
  await page.fill('#participantInput', 'GER 1');
  await page.click('#addParticipantBtn');
  await stopServer();
  await page.reload();
  assert.equal(await page.locator('#participantList li').count(), 1);
  await page.click('#captureBtn');
  assert.equal(await page.textContent('#capCount'), '1');
  step('local mode: reload without server works');

  // Server mode: connect, then lose the server
  await startServer();
  await page.reload();
  await page.click('#settingsBtn');
  await page.click('#createBtn');
  await page.waitForSelector('#dialog:not([hidden])');
  await page.click('#dialogOk');
  await page.waitForFunction(() => document.getElementById('statusText').textContent.startsWith('Server verbunden'), null, {timeout: 10000});
  await sleep(500);
  const code = await page.inputValue('#srvCode');
  await page.click('#drawerClose');
  await stopServer();

  await page.reload();
  assert.equal(await page.locator('#participantList li').count(), 1, 'race data comes from the local cache');
  await page.waitForFunction(() => /unterbrochen|hergestellt/.test(document.getElementById('statusText').textContent), null, {timeout: 10000});
  await page.click('#captureBtn');
  assert.equal(await page.locator('.pending-mark').count(), 1);
  const second = await context.newPage();
  await second.goto(BASE + '#r=' + code);
  assert.equal(await second.locator('#participantList li').count(), 1);
  await second.close();
  step(`server mode: race ${code} opens without server, capture buffered`);

  await startServer();
  await page.waitForFunction(() => document.getElementById('statusText').textContent.startsWith('Server verbunden'), null, {timeout: 20000});
  await sleep(2000);
  assert.equal(await page.locator('.pending-mark').count(), 0);
  const snapshot = await (await fetch(BASE + 'api/races/' + code)).json();
  assert.equal(snapshot.state.captures.length, 2);
  step('buffered capture reaches the server after it is back');

  console.log('\nOffline start tests passed.');
} finally {
  await browser.close();
  await stopServer();
}

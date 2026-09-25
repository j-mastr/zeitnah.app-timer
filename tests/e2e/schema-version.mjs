// Schema versioning (docs/groups.md): what a client does when its version doesn't match the
// server's or the data stored in the browser. Older and newer app versions are simulated by
// rewriting SCHEMA_VERSION in the served page.
//
// Usage: BASE_URL=http://127.0.0.1:8000/ node tests/e2e/schema-version.mjs
// (needs the PHP server and, for the WebSocket part, app:websocket-server; npm install first)
import {chromium} from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000/';
const step = (name) => console.log('✓', name);
const waitStatus = (page, prefix, timeout = 10000) =>
  page.waitForFunction((p) => document.getElementById('statusText').textContent.startsWith(p), prefix, {timeout});

const res = await fetch(new URL('api/config', BASE));
const serverSchema = (await res.json()).schema;
assert.ok(Number.isInteger(serverSchema), 'the server announces its schema version');

/** A page whose app announces `version` (a newer one gets identity migration steps). */
async function pageWithSchema(browser, version){
  const ctx = await browser.newContext({locale: 'en-US', viewport: {width: 1100, height: 900}});
  const page = await ctx.newPage();
  page.on('pageerror', (e) => { throw e; });
  await page.route((url) => url.pathname === new URL(BASE).pathname, async (route) => {
    const response = await route.fetch();
    let body = await response.text();
    body = body.replace(/const SCHEMA_VERSION = \d+;/, `const SCHEMA_VERSION = ${version};`);
    if(version > serverSchema){
      const steps = Array.from({length: version - serverSchema}, (_, i) => `${serverSchema + i}: (s) => s`).join(', ');
      body = body.replace('const SCHEMA_MIGRATIONS = {};', `const SCHEMA_MIGRATIONS = {${steps}};`);
    }
    await route.fulfill({response, body});
  });
  return {ctx, page};
}

async function createRace(){
  const r = await fetch(new URL(`api/races?schema=${serverSchema}`, BASE), {method: 'POST'});
  return (await r.json()).code;
}

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? {executablePath: process.env.CHROMIUM_PATH} : {});
try {
  // An older client: refused, keeps recording (buffered), other actions locked, reload notice.
  {
    const code = await createRace();
    const {ctx, page} = await pageWithSchema(browser, serverSchema - 1);
    await page.goto(BASE + '#r=' + code);
    await waitStatus(page, 'Update required');
    assert.equal(await page.isVisible('#outdatedBanner'), true);
    assert.match(await page.textContent('#outdatedText'), /older than the server/);
    await page.click('#captureBtn');
    assert.equal(await page.textContent('#capCount'), '1');
    const cache = await page.evaluate((c) => JSON.parse(localStorage.getItem(Object.keys(localStorage).find(k => k.startsWith('zeitnah.cache:') && k.endsWith('|' + c)))), code);
    assert.equal(cache.pending.length, 1, 'the capture stays buffered in the cache');
    // Everything that needs the connection is locked, like while offline.
    assert.equal(await page.evaluate(() => document.body.classList.contains('lock-normal')), true);
    const server = await (await fetch(new URL(`api/races/${code}?schema=${serverSchema}`, BASE))).json();
    assert.equal(server.state.captures.length, 0, 'nothing reached the server');
    // The reload brings the current page, which delivers the buffered capture.
    await page.unrouteAll();
    await page.reload();
    await waitStatus(page, 'Connected to server');
    await page.waitForFunction(() => !document.getElementById('statusText').textContent.includes('pending'));
    const after = await (await fetch(new URL(`api/races/${code}?schema=${serverSchema}`, BASE))).json();
    assert.equal(after.state.captures.length, 1, 'the buffered capture arrived after the reload');
    assert.equal(await page.isVisible('#outdatedBanner'), false);
    await ctx.close();
    step('an older client is refused, buffers captures and delivers them after a reload');
  }

  // A newer client with an older server: back to local mode, the cache stays.
  {
    const code = await createRace();
    const {ctx, page} = await pageWithSchema(browser, serverSchema + 1);
    await page.goto(BASE + '#r=' + code);
    await waitStatus(page, 'Stored in this browser');
    await page.waitForFunction(() => document.getElementById('toast').textContent.includes('older version'));
    assert.equal(await page.evaluate(() => location.hash), '');
    await ctx.close();
    step('a newer client refuses an older server and stays local');
  }

  // Local data written by a newer version: shown, never written, reload notice.
  {
    const {ctx, page} = await pageWithSchema(browser, serverSchema);
    await page.goto(BASE);
    const stored = {schema: serverSchema + 1, name: 'Future', archived: false, sport: 'generic', participants: [{id: 'p1', name: 'NED 7'}], kinds: [], worksets: [], captures: [], somethingNew: [1]};
    await page.evaluate((s) => { localStorage.clear(); localStorage.setItem('zeitnah.local', JSON.stringify(s)); }, stored);
    await page.reload();
    assert.equal(await page.isVisible('#outdatedBanner'), true);
    assert.match(await page.textContent('#outdatedText'), /newer version/);
    assert.equal(await page.locator('#participantList li').count(), 1);
    await page.click('#captureBtn');
    await page.waitForFunction(() => document.getElementById('toast').textContent.includes('outdated'));
    assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('zeitnah.local'))), stored, 'the newer data is untouched');
    await ctx.close();
    step('local data of a newer version is read-only');
  }
} finally {
  await browser.close();
}
console.log('\nSchema version tests passed.');

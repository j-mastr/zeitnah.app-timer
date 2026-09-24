#!/usr/bin/env node
// Renders the app icons into public/icons/ with headless Chromium (Playwright).
// Usage: node tools/generate-icons.mjs   (after `npm install && npx playwright install chromium`)
// Bump VERSION in public/sw.js afterwards so installed apps pick up the new icons.
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const out = path.join(root, 'public/icons');
fs.mkdirSync(out, {recursive: true});

const NAVY = '#0A1929';
const AMBER = '#E0A63B';
const LIGHT = '#EAF0F5';

// Stopwatch glyph drawn around (0,0), roughly 330 × 360 units.
const glyph = `
  <rect x="-34" y="-200" width="68" height="34" rx="12" fill="${AMBER}"/>
  <rect x="-12" y="-172" width="24" height="26" fill="${AMBER}"/>
  <rect x="104" y="-158" width="44" height="26" rx="10" fill="${AMBER}" transform="rotate(45 126 -145)"/>
  <circle r="150" fill="${NAVY}" stroke="${AMBER}" stroke-width="30"/>
  ${Array.from({length: 12}, (_, i) => `<rect x="-5" y="-122" width="10" height="${i % 3 === 0 ? 26 : 14}" rx="4" fill="${LIGHT}" opacity="${i % 3 === 0 ? 0.9 : 0.45}" transform="rotate(${i * 30})"/>`).join('')}
  <path d="M0 0 L0 -96" stroke="${AMBER}" stroke-width="18" stroke-linecap="round"/>
  <path d="M0 0 L58 34" stroke="${LIGHT}" stroke-width="18" stroke-linecap="round"/>
  <circle r="17" fill="${LIGHT}"/>`;

// scale: glyph size; radius: corner radius of the background (0 = full-bleed square).
const svg = ({scale, radius}) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <rect width="512" height="512" rx="${radius}" fill="${NAVY}"/>
  <g transform="translate(256 276) scale(${scale})">${glyph}</g>
</svg>`;

const standard = svg({scale: 1, radius: 0});
const maskable = svg({scale: 0.76, radius: 0}); // stays inside the 80 % safe zone
fs.writeFileSync(path.join(out, 'icon.svg'), svg({scale: 1, radius: 96}).replace(/\n\s*/g, ''));

const browser = await chromium.launch();
const page = await browser.newPage();
async function render(markup, size, file) {
  await page.setViewportSize({width: size, height: size});
  await page.setContent(`<html><body style="margin:0">${markup.replace('<svg ', `<svg width="${size}" height="${size}" `)}</body></html>`);
  await page.screenshot({path: path.join(out, file), clip: {x: 0, y: 0, width: size, height: size}});
}
await render(standard, 192, 'icon-192.png');
await render(standard, 512, 'icon-512.png');
await render(maskable, 512, 'icon-maskable-512.png');
await render(standard, 180, 'apple-touch-icon.png');
await browser.close();
console.log('Icons written to', path.relative(root, out));

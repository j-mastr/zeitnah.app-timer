/*
 * Service worker: lets the app start without a network connection after the first visit.
 *
 * - The app page (the scope URL) is served network-first with a short timeout and falls
 *   back to the cached copy, so online users always get the current version while a
 *   missing or flaky connection still starts the app within a few seconds.
 * - Icons and the manifest are precached (cache-first). Bump VERSION when they change.
 * - Google Fonts (Latin subsets) are cached so the page looks the same offline.
 * - The API and WebSocket traffic are never touched; offline changes are buffered by the
 *   app itself in localStorage.
 */
const VERSION = 'v1';
const PREFIX = 'finish-line-timer-';
const SHELL_CACHE = PREFIX + 'shell-' + VERSION;
const FONT_CACHE = PREFIX + 'fonts-' + VERSION;

const SCOPE = self.registration.scope;
const SHELL_URL = SCOPE;
const API_PATH = new URL('api/', SCOPE).pathname;
const ASSETS = [
  'manifest.webmanifest',
  'icons/icon.svg',
  'icons/icon-192.png',
  'icons/icon-512.png',
  'icons/icon-maskable-512.png',
  'icons/apple-touch-icon.png',
].map((path) => new URL(path, SCOPE).href);
// Must match the stylesheet link in frontend/index.html.
const FONT_CSS = 'https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap';
const FONT_ORIGINS = ['https://fonts.googleapis.com', 'https://fonts.gstatic.com'];
const NAVIGATION_TIMEOUT_MS = 2500;

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    await cache.addAll([SHELL_URL, ...ASSETS]);
    await cacheFonts().catch(() => {}); // fonts are optional
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keep = [SHELL_CACHE, FONT_CACHE];
    for (const key of await caches.keys()) {
      if (key.startsWith(PREFIX) && !keep.includes(key)) await caches.delete(key);
    }
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);

  if (request.mode === 'navigate' && url.origin === self.location.origin
      && url.href.startsWith(SCOPE) && !url.pathname.startsWith(API_PATH)) {
    event.respondWith(appPage(request));
  } else if (ASSETS.includes(url.origin + url.pathname)) {
    event.respondWith(cacheFirst(request, SHELL_CACHE));
  } else if (FONT_ORIGINS.includes(url.origin)) {
    event.respondWith(staleWhileRevalidate(request, FONT_CACHE));
  }
  // Everything else (API, other hosts) goes to the network as usual.
});

async function appPage(request) {
  const cache = await caches.open(SHELL_CACHE);
  const network = fetch(request).then((response) => {
    if (response.status >= 500) throw new Error('server error ' + response.status);
    if (response.ok && !response.redirected) cache.put(SHELL_URL, response.clone());
    return response;
  });
  try {
    return await Promise.race([network, rejectAfter(NAVIGATION_TIMEOUT_MS)]);
  } catch (error) {
    const cached = await cache.match(SHELL_URL);
    if (cached) {
      network.catch(() => {}); // a late response still refreshes the cache
      return cached;
    }
    return network;
  }
}

async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request, {ignoreSearch: true});
  if (cached) return cached;
  const response = await fetch(request);
  if (response.ok) (await caches.open(cacheName)).put(request, response.clone());
  return response;
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  const network = fetch(request).then((response) => {
    if (response.ok) cache.put(request, response.clone());
    return response;
  });
  if (cached) {
    network.catch(() => {});
    return cached;
  }
  return network;
}

/** Precaches the font stylesheet and its Latin font files (the ones the UI needs). */
async function cacheFonts() {
  const cache = await caches.open(FONT_CACHE);
  const response = await fetch(FONT_CSS, {mode: 'cors'});
  if (!response.ok) return;
  const css = await response.clone().text();
  await cache.put(FONT_CSS, response);
  const fontUrls = css.split('/* ')
    .filter((block) => /^latin(-ext)? \*\//.test(block))
    .flatMap((block) => [...block.matchAll(/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/g)].map((m) => m[1]));
  await Promise.all(fontUrls.map(async (url) => {
    const font = await fetch(url, {mode: 'cors'});
    if (font.ok) await cache.put(url, font);
  }));
}

function rejectAfter(ms) {
  return new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), ms));
}

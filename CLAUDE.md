# CLAUDE.md

Guidance for coding agents working on this repository. It records every requirement the
product owner has specified so far, how they are implemented, and the rules for changing
them. Read it fully before making changes; update it when requirements change.

## Product in one paragraph

A timekeeping app for the finish line of a race. A large clock shows the current time; a
button (or the space bar) records the exact time of each finish-line crossing. Participants
approaching the line together can be put into an "approaching the finish" queue in the order
they cross, so
recorded times are assigned to them automatically. Data lives in the browser by default;
optionally several devices share one race through the server with real-time sync. The
primary device is an iPad (touch), laptops with keyboards are also used.

The app supports several sports: **generic** (the sport-neutral default), **sailing**
(regattas: participants are boats, identified by sail number or name), **running**
(participants are runners), **swimming** (participants are swimmers, a race is a meet)
and **motor sports** (participants are vehicles). That vocabulary exists **only in the
user-facing text sets**; the code itself is sport-neutral. The sport is a per-race field
(`state.sport`), picked from a select in Settings and synced like the race name.

## Vocabulary

| Concept in code | Sailing UI (de / en) |
| --- | --- |
| `race` (one timed event, has a code, name, sport, archive flag) | Regatta / regatta |
| `participant` (`{id, name}`) | Boot, Segelnummer / boat, sail number |
| `capture` (`{id, ts, tzOffset, participantId}`) — one recorded finish-line crossing | Zieldurchlauf / finish |
| `ranking` — the queue of participants approaching the line, in expected crossing order | Im Zieleinlauf / Approaching the finish |

The other sport sets (`generic`, `running`, `swimming`, `motor`) use the same concepts with
their own vocabulary, e.g. `participant` is "Runner"/"Läufer" in `running`, "Swimmer"/
"Schwimmer" in `swimming`, "Vehicle"/"Fahrzeug" in `motor`.

## Repository layout

```
frontend/index.html          The entire UI: HTML + CSS + vanilla JS in one file, no build step
public/index.php             Symfony front controller (classic, no symfony/runtime)
public/sw.js                 Service worker (offline start), public/manifest.webmanifest, public/icons/
bin/console                  Symfony console
config/                      bundles.php, packages/framework.yaml, routes.yaml, services.yaml
src/Controller/AppController.php   GET / → serves frontend/index.html with server config injected
src/Controller/ApiController.php   HTTP API (also the fallback transport)
src/EventListener/CorsListener.php CORS for /api/*
src/Race/Database.php           PDO wrapper, driver-aware transactions, schema DDL
src/Race/OperationReducer.php   AUTHORITATIVE operation semantics (mirrored in JS!)
src/Race/RaceRepository.php  Race store: create, snapshot, event log, applyOperations
src/Race/ClientConfig.php       serverUrl / wsUrl for browsers
src/Command/InstallCommand.php     app:install – creates tables (idempotent)
src/Command/WebSocketServerCommand.php  app:websocket-server
src/WebSocket/SyncServer.php       WebSocket protocol, subscriptions, broadcasting
src/WebSocket/ClientSession.php    Per-connection state
tests/reducer-parity.mjs|.php      JS vs PHP reducer equivalence test
tests/text-keys.mjs                Text sets complete in every language, all used keys resolve
tests/e2e/sync-smoke.mjs           Playwright smoke test with two browser clients
tests/e2e/offline-start.mjs        PWA test: starts/stops its own PHP server, checks offline start
tools/generate-icons.mjs           Renders public/icons/*.png from the SVG definition inside it
```

## Hard rules

1. **Language:** all source code, identifiers, comments, commit messages and server error
   codes are English. User-facing text lives only in the `TEXTS` table of the frontend
   (German and English) — never hard-code UI strings, not even in the HTML markup.
   Only exception: name/description in `public/manifest.webmanifest` (the installed app's
   name can't be localised per user; it is the product name, "zeitnah", not translated).
2. **Sport-neutral code.** Identifiers, operation types, state fields, database tables,
   API routes, storage keys, CSS classes and comments use the vocabulary above — never a
   specific sport's terms (boat, regatta, sail number, runner, vehicle, …). Sport-specific
   wording belongs in a sport text set only. `TEXTS.common` holds texts that fit every
   sport; each sport set (`generic`, `sailing`, `running`, `swimming`, `motor`, listed in
   `SPORTS`) holds everything that names participants or races (and its default title).
   `state.sport` (per race, changed with `race.setSport`) selects the active set; `t()`
   looks up that set, then `common`, then German. A new sport = a new key in `SPORTS` plus
   a `TEXTS` entry with the same keys as the others (see the key check below).
   Exceptions: the legacy storage migration (reads old `boats`/`boatId` fields) and the
   CSV header pattern, which is itself a text (`import.headerPattern`).
3. **Frontend stays a single file with vanilla JS/HTML/CSS** and no build step
   (plus the static PWA files in `public/`). If a
   framework ever becomes necessary, the product owner wants React — ask first.
   Only external resource: Google Fonts (the page must look fine if they fail to load).
4. **Two reducers, one behaviour.** `OperationReducer.php` (server, authoritative) and
   `applyOp()` in `frontend/index.html` (optimistic client) must produce identical results
   and identical error codes. Run `node tests/reducer-parity.mjs` after every change to
   either; extend the generator in that test when adding operations or fields.
5. **Every text key exists in both `de` and `en`**, and every sport set has the same keys.
   `node tests/text-keys.mjs` checks this and that every key used in code/markup resolves.
6. The same `frontend/index.html` is also published standalone as a claude.ai artifact.
   It must work without the server (local mode), with `window.TIMER_CONFIG === null`,
   wrap every `localStorage` access in try/catch, and use `window.claude.use('downloads')`
   for file downloads when available (plain Blob download otherwise).
7. Don't use `cboden/ratchet`: its latest release (0.4.4) requires symfony/http-foundation
   ≤ 6 and conflicts with Symfony 7. The WebSocket server uses `ratchet/rfc6455` +
   `react/socket` directly.
8. Never use `window.confirm/alert/prompt`; use `askConfirm()` (they are blocked in some
   embedded contexts and block the event loop).

## Functional requirements

### Clock and recording times
- Large clock `HH:MM:SS.cc` (centiseconds smaller), date below in the UI language.
  Uses the device clock (devices are expected to be NTP-synced; no server offset yet).
- "Record time" button and **Space** record a capture as a full timestamp: `ts` (`Date.now()`,
  Unix milliseconds — the absolute instant, date included) plus `tzOffset`
  (`-new Date().getTimezoneOffset()`, minutes east of UTC on the recording device).
  Captures are always rendered in their own `tzOffset` (`fmtTime` / `fmtDate` /
  `fmtDateLong` / `fmtIso`), so a race recorded elsewhere or before a DST change still shows
  the wall-clock time it was taken at. `tzOffset` is `null` only for data from earlier
  versions; those captures fall back to this device's zone.
  - If the ranking is non-empty, the capture is assigned to its **first** participant and that
    participant leaves the ranking. Otherwise the capture is stored unassigned.
- A link-styled button under the big one ("Zeit ohne Zuordnung erfassen" / "Record a time
  without assigning it", `.link-btn`) and the key **0** (top row or numpad) record a capture
  that stays unassigned, whatever the ranking holds (`recordTime(null)`).
- That link (`#captureFreeRow`) and the assignment hints (`#hintDirect`, `#hintFree`) are
  shown **only while the ranking is not empty** (`renderClockCard()`): with nobody
  approaching, the big button already records an unassigned time. The space hint stays, with
  its text switching between `clock.hintNext` ("next participant approaching") and
  `clock.hintRecord` ("record a time") — it is set on every render, so it carries no
  `data-i18n`. The keys keep working either way, and the shortcuts dialog always lists all.
- **Double-click / double-tap on a participant name** (in either list) records a time for that
  participant now; if the participant was in the ranking (at any position) it is removed from it.
- **Keys 1–9** (top row or numpad) record a time for the participant at that position of the
  ranking (same removal rule). The first nine ranking rows show their key as a small
  keycap badge.
- The 1–9 mapping **freezes for 5 s** (`SHORTCUT_HOLD_MS`) after each such capture, and every
  further key press extends the freeze. In a dense field the operator can type the order the
  participants were ranked in — ranked 1-2-3 but arriving 2-1-3 is typed `2 1 3`, not
  `2 1 1` — without re-reading the badges between captures. `shortcutIds()` returns the
  frozen ids (`shortcutHold`) or the live ranking; badges render from it, so they stay put
  while frozen. A key whose participant has left the ranking does nothing, and a participant
  ranked during the freeze gets no badge until it expires (a timer re-renders then).
  Space, double-tap and the button are unaffected: they never freeze and always act on the
  live ranking.
- Shortcuts are ignored while typing in inputs/selects, while a dialog or the settings
  panel is open.
- Shortcut hints are shown as keycap pictograms; a key and its description never wrap
  apart (`.hint-pair` is `nowrap`; line breaks happen between pairs). On touch devices
  (`hover: none` and `pointer: coarse`) the clock card's hint line is hidden — there is no
  hardware keyboard to hint at.
- **Letter keys** drive the participant list, same actions as the controls themselves
  (`LIST_SHORTCUTS`, plain keys, no modifier — they stay the same in every language):
  **F** focus the ranking's quick search · **N** focus the add-participant field ·
  **A** / **O** / **H** the three filters · **S** cycle the sort field · **D** reverse it.
- A **shortcuts dialog** (`#shortcuts`) lists them all, closed with an ✕ in its top right
  corner (`.modal-head`, like the settings drawer). It opens from "Tastenkürzel anzeigen"
  / "Show keyboard shortcuts" in the settings, from the footer link, and by holding
  **Ctrl/Cmd** alone for `SHORTCUTS_HOLD_MS` (1.5 s) — pressing any other key within that
  time cancels the hold, so ⌘C and friends never open it. It closes on release, on window
  blur, on Escape, on the backdrop and on its Close button. Ctrl/Cmd does not open it while
  an input has the focus, and the recording shortcuts are ignored while it is open.
- **Footer** (`.site-footer`, below everything, dim text with `.foot-link` links): a link
  that opens the dialog — "Press ⌘/Ctrl to show the shortcuts." with `MOD_KEY` picking the
  label from the platform, or just "Tastenkürzel anzeigen" / "Show shortcuts" on touch
  devices, where there is no modifier to press (both labels are in the markup, the media
  query picks one) — and a line
  "Made with ❤️ and Claude in Hamburg · Star on GitHub" linking to
  https://github.com/j-mastr/zeitnah.app-timer.

### Finishes list ("Zieldurchläufe")
- Newest first; each row shows place `#n` (by time ascending), time, delta to the
  previous capture ("First" for the first), the date (spelled out only when the capture is
  not from today; the full ISO timestamp is always in the row's `title`), a participant
  dropdown to assign/reassign
  (including "(deleted participant)" if the participant was deleted) and a delete button.
- Captures not yet confirmed by the server show a "⏳ not synced" marker.
- Header count uses the same `.count` style as the other panels.
- CSV export via the ↧ icon button in the panel header (`.icon-btn`: borderless, muted,
  right-aligned, tooltip only), in ascending order; header and file name come from the texts
  (sailing: `Platz;Zeitstempel;Uhrzeit;Boot` / `Place;Timestamp;Time;Boat`). The
  *Zeitstempel/Timestamp* column is the full ISO 8601 timestamp with date and offset
  (`2026-09-25T14:33:12.45+02:00`), the *Uhrzeit/Time* column the same instant as a readable
  local time.

### Participants (overall list)
- Add by name (sailing UI: sail number or boat name; max 60 chars; whitespace
  normalised). Names are unique case-insensitively; duplicates are refused with a toast.
- Rename inline (✎), delete (✕, confirmation; recorded times keep their participant id).
- CSV import via the ↥ icon button in the panel header (same `.icon-btn` style):
  one participant per line, first column (`;` or `,` separated, quotes stripped),
  an optional header line matching the text `import.headerPattern` is skipped (sailing:
  `name`, `boot`, `boat`, `segelnummer`, `sail`…), existing names are skipped.
- Each row shows the latest recorded time of the participant, with `+x` if it has more.
- Filters are toggle buttons in one bar: **All**, **No finish time**, **Hide approaching**.
  "No finish time" and "Hide approaching" can be active at the same time; clicking "All"
  turns both off; activating either turns "All" off. By default ranked participants are shown
  in the overall list too, marked with an "Approaching #n" badge and a ↩ button instead of →/✕.
- Sorting: **Order added / Name / Finish time** plus a direction toggle (↑/↓).
  Finish time ascending uses the participant's *first* recorded time, descending its *last*
  recorded time; participants without a time always come last. Name sort is locale-aware and
  numeric.
- Filters, sort order and language are per-device preferences (never synced).

### Ranking ("Im Zieleinlauf" / "Approaching the finish")
- Holds the expected crossing order of participants approaching the line together.
- Add with → in the overall list or via the **fuzzy quick search** (ranking: prefix >
  substring > characters in order). Keyboard in the search field: **Enter** takes the
  selected suggestion, or the first one when nothing is selected · **↓/↑** move the selection
  (the first press of either selects the first suggestion, then it wraps) · **Tab** selects
  the first suggestion while nothing is selected, and otherwise still leaves the field ·
  **Escape** clears and leaves. The selection is kept by participant id while typing, as long
  as that participant is still among the matches (`selectedMatchId`); the list is a
  `role="listbox"` with `aria-activedescendant` on the input.
- Reorder with ▲▼ buttons and drag & drop (drag handle ⠿; pointer events with document-level
  listeners so it works with touch and when the pointer leaves the handle). Only the handle
  has `touch-action: none` — the row must not, or a swipe over a long ranking list would be
  captured by the drag instead of scrolling the page.
- Remove with ↩ (the participant stays in the overall list).
- A participant is in the ranking at most once.

### Race name
- Shown instead of the default title (`header.defaultName`; sailing: "⛵ Zielzeiten" /
  "⛵ Finish Times"); edited inline via a small ✎ button; stored in the backend state; also used for `document.title`.

### Sport
- Selects the vocabulary (see Vocabulary above): `generic` (default for new races),
  `sailing`, `running`, `swimming`, `motor`. A select in Settings (below Language) changes
  it via `race.setSport`; the choice is per-race state, synced like the race name — not a
  per-device preference. Changing it re-renders every text immediately (`applyI18n()` runs
  on every `renderAll()`).

### Layout (responsive, purely width-based)
- One breakpoint at **700 px viewport width**, independent of device type or orientation.
- `< 700 px`: single column — clock, ranking, participants, finishes.
- `≥ 700 px`: clock full width on top; left column (320 px) ranking + participants; right
  column finishes. Content centred with `max-width: 1200px`. The rows are
  `auto auto 1fr`, so a long finishes list (it spans both lower rows) adds its slack to the
  last row instead of spreading it over the ranking row — the participants panel keeps its
  14 px distance to the ranking and the whitespace ends up below it.
- **Touch devices** (`@media (hover: none) and (pointer: coarse)`): the row buttons (`.mini`),
  segmented filters, assign selects and search results grow to ~42 px hit targets (WCAG 2.5.5),
  and the fixed left column widens to 360 px to keep the participant names readable. `:hover`
  styles only apply under `@media (hover: hover)` so touch taps don't leave sticky hover states.
- **Nothing may be wider than the viewport** (a phone must never need pinch-zoom): grid
  tracks are `minmax(0, …)`, `.panel` has `min-width:0`, and the finishes rows and
  participant rows are wrapping flex boxes, so the assignment select (whose min-content is
  its longest option) and the row buttons move to a second line instead of stretching the
  page. The settings drawer is a fixed **shell** (`width:min(420px,100%)`, `overflow:hidden`)
  containing the sliding `.drawer-panel`, so nothing is ever laid out beyond the right edge —
  iOS Safari counts that towards the page width, which inflated `100vw` and with it the
  drawer, pushing its ✕ off-screen. `html{overflow-x:clip}` is the backstop (`clip`, not
  `hidden`: no scroll container, so the sticky clock keeps working) and
  `-webkit-text-size-adjust:100%` stops iOS from inflating text.
  On touch devices **every focusable field is at least 16 px** (`input, select, textarea,
  .input, .edit-input, select.assign` in the touch media query): below that, iOS zooms into
  the field on focus, which enlarges the layout and scrolls controls — the drawer's ✕ among
  them — out of view. Desktop keeps 14 px, where no browser auto-zooms.
- Dark navy theme with amber accent, light theme via `prefers-color-scheme`; colours are
  CSS tokens on `:root`. Numbers use Space Mono, text IBM Plex Sans. Touch targets and
  safe-area insets matter (iPad).

### Pinned clock
- A borderless 📌 `.icon-btn` in the clock card's top row (its own flex row, so the clock
  keeps the full width) makes the card sticky at the top while scrolling; it is greyscale and
  dimmed while inactive and shows its colours when the clock is pinned. Pinned, the card
  sticks at `top: var(--safe-top)` (the `env(safe-area-inset-top)` token on `:root`).
  A scroll listener sets `body.clock-stuck` while the card actually sits at the top edge;
  only then does it square its top corners and paint a `::before` strip over the status bar,
  so on iOS standalone nothing scrolls visibly through that gap. The strip is painted, never
  laid out — sticking adds no headroom and shifts nothing below the card — at every window width, not just
  the narrow layout. `prefs.pinClock` (per-device, never synced, default on) toggles
  `body.pin-clock`; the button shows the state via `.on` and `aria-pressed`.

### Language
- German / English switch in the settings panel. Default from `navigator.language`.
  Texts come from `TEXTS[state.sport]` (sport-specific) and `TEXTS.common`. Static text uses
  `data-i18n`, `data-i18n-placeholder`, `data-i18n-title`; dynamic text uses `t(key, vars)`.
  Language is a per-device preference, unlike sport (see above), which is per-race.

### Storage backends
- **Local (default):** state in `localStorage['zeitnah.local']`.
  Captures are stored with `ts` **and** `tzOffset`, so the date and time zone survive a
  reload and an export.
  Data of earlier versions is migrated once on load: `segel-zielzeit-v1` (one key incl.
  preferences) and `regatta-timer.*` keys, both with the old field names `boats`/`boatId`
  (`normalizeState()` accepts them) and no `sport` field — migrated data is tagged
  `sport:'sailing'` since that was the only sport those versions supported. Old server
  caches are dropped (rebuilt from the server).
- **Server:** connect by race code or create a new race (the server generates a
  random 6-character code from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`; codes are
  case-insensitive, non-alphanumerics ignored).
- The server URL defaults to the server that delivered the page: the backend replaces the
  placeholder `/*SERVER_CONFIG*/null` in `index.html` with `{"serverUrl": "..."}`. It can
  be overridden under "Advanced settings" (`prefs.serverUrl`); without a default the
  field is required.
- The active connection `{serverUrl, code}` is stored in
  `localStorage['zeitnah.connection']` and resumed after a reload.
- **Recent connections** (`localStorage['zeitnah.recent']`, per device, never synced): the
  last `RECENT_MAX` = 3 races this browser connected to, newest first, as
  `{serverUrl, code, name}`. Written on every connect and updated with the race name once it
  is known (`rememberConnection()` / `rememberName()`). Connecting passes `keepKnown`, so a
  reconnect keeps the name from last time instead of blanking it until the state arrives;
  `rememberName()` is authoritative, so clearing a race's name clears it in the list too. In local mode the settings list them
  above "Create a new race" as **quick-connect buttons** labelled with the name, or the code
  when the race has none; the row's second column shows the code (when the name is the label)
  and the server host (only when it differs from the server this page would use anyway).
- **URL fragment `#r=CODE`** connects automatically on load (and on `hashchange`); while
  connected the fragment always reflects the current code; it is removed on disconnect.
- **Initialisation prompt:** when connecting fresh (by code, link or "new race") to a
  race that is still empty on the server (`seq === 0`) while this browser has local
  data, ask whether to upload it. Yes → send `state.merge`. No, or race already has
  data → connect. The local data is always kept in the browser, whatever the answer.
  Not asked when resuming a stored connection.
- **Settings → Sync data** (server mode):
  - *Upload local data*: merge local → server; the local data stays in this browser
    (reset it explicitly in local mode if you want it gone). Merging never deletes:
    participants are matched by id or case-insensitive name, missing ranked participants appended,
    captures added unless their id exists, name only set if the race has none, sport
    only set if the race still has the default (`generic`).
  - *Copy server data to this browser*: overwrite local data with the race (never merge).
- **Disconnect** switches back to local storage; the server race is untouched.

### Real-time sync and offline behaviour
- WebSocket push with automatic fallback to HTTP polling (1.5 s; 3 s while offline) and
  WebSocket reconnection with exponential backoff (max 30 s). Heartbeat every 15 s,
  connection considered dead after 45 s of silence.
- Optimistic updates: `view = confirmed server state + pending local ops`. Confirmed state,
  sequence number and pending ops are cached per server+code in
  `localStorage['zeitnah.cache:<serverUrl>|<code>']`, so buffered changes survive
  reloads.
- Allowed while not connected (buffered, sent on reconnect): `capture.add`,
  `capture.assign`, `capture.delete`, `ranking.add`, `ranking.remove`, `ranking.move`
  (`OFFLINE_OPS` in the frontend).
- Everything else (rename race, add/import/rename/delete participants, merge, archive) is
  disabled until the connection is back: elements marked `data-edit="normal"` are dimmed
  via `body.lock-normal`, and `perform()` refuses with a toast.
- Status (local / connecting / connected / connection lost, plus pending count and
  "Archived") is shown in a pill next to the settings button; clicking it opens settings.
- Over a WebSocket the pill also shows how many clients are on this race, after the code:
  "Server verbunden · ABC123 (5)". The count comes from the server's `presence` message
  (`backend.clientCount()`); while the HTTP fallback is in use it is unknown and omitted,
  since polling requests can't be attributed to a client. The settings panel spells the same
  number out in its status details ("2 Geräte verbunden" / "2 devices connected",
  `settings.clientsOne` / `settings.clientsMany`).

### Archiving
- Settings → "Archive race" (server mode only, confirmation, irreversible).
- The server rejects every operation on an archived race (`archived`).
- Archiving empties the ranking (the reducers do it, so both sides agree): nothing is
  approaching the line any more.
- Clients show a banner, dim every `data-edit` element (`body.lock-all`) and refuse all
  operations. `body.archived` hides the clock card and the ranking panel entirely and
  re-lays the grid to participants + finishes. A local copy pulled from an archived race is
  editable again.
- In local mode the settings show "Reset local data" instead of "Archive".

### Progressive web app (offline start)
- After the first visit the page starts without a network connection and can be
  installed ("Add to Home Screen" on the iPad, install prompt in Chrome/Edge).
- `public/sw.js`: the app page (scope URL) is network-first with a 2.5 s timeout and
  falls back to the cached copy (also on 5xx); manifest and icons are precached
  (cache-first); Google Fonts' Latin subsets are cached (stale-while-revalidate, optional —
  installation must not fail without them); `/api/*` and WebSocket traffic are never
  cached. Offline changes are buffered by the app itself, not by the service worker.
- Bump `VERSION` in `sw.js` whenever the manifest, icons or `sw.js` itself change.
  `FONT_CSS` in `sw.js` must match the stylesheet link in `index.html`. The HTML itself
  needs no version bump (network-first).
- The service worker is only registered when the page is served by the backend
  (`window.TIMER_CONFIG` set) in a secure context (HTTPS or localhost) — never in the
  standalone/artifact copy.
- iOS standalone: `black-translucent` status bar, so everything respects
  `env(safe-area-inset-*)` (the top inset is the `--safe-top` token; the pinned clock covers
  that strip instead of starting below it — see Pinned clock).

### Settings panel (slide-over from the right)
- Shell + `.drawer-panel` (see the overflow rule under Layout). The shell is never hidden
  with `visibility`: it only switches `pointer-events`, and the closed drawer is kept out of
  the tab order and the a11y tree with the `inert` attribute (set in the markup, toggled in
  `openSettings`/`closeSettings`). The panel is therefore always laid out, and opening
  animates nothing but its `transform` — a panel that becomes visible in the same frame does
  not animate reliably (Safari), and a transitioned `visibility` also swallowed the focus
  call for the ✕, which left Escape without a handler inside the drawer.
- While the drawer or a modal is open the page behind it does not scroll: `lockScroll()`
  (reference-counted, so a dialog opened from the drawer doesn't unlock it) fixes the body at
  its current offset (`body.scroll-locked` + `top: -scrollY`, since `overflow:hidden` on the
  body is ignored by iOS Safari) and restores the offset on close. Focus is always returned
  with `{preventScroll: true}` — otherwise focusing the settings button scrolls back to the
  top and undoes the restore. The panel and the modal backdrop use
  `overscroll-behavior: contain`.
- Language · Sport (select, `data-edit="normal"`) · "Show keyboard shortcuts" link · local mode: status, race code +
  Connect, recent connections (quick connect), "Create a new race on the server", advanced
  settings (server URL), "Reset local data" · server mode: status with transport, client count and pending count, code (read-only), direct
  link `<serverUrl>/#r=<CODE>` with copy button, read-only server URL under advanced
  settings, Disconnect, Sync data (upload / copy to browser), Archive (or archived notice).
  A "Show keyboard shortcuts" link sits at the very bottom, below both backend sections.

## Architecture

### Operations (shared contract between client and server)

Every change is an operation `{opId, type, ...fields}`; `opId` is a client-generated
unique id (`[A-Za-z0-9_-]{1,64}`), which makes resending idempotent. Entity ids match
`[A-Za-z0-9_-]{1,40}` and are generated by the client (`uid()`).

| type | fields | semantics |
| --- | --- | --- |
| `race.rename` | `name` (null/blank = default) | max 80 chars |
| `race.archive` | – | sets `archived: true` and clears the ranking |
| `race.setSport` | `sport` (one of `SPORTS`) | rejects with `invalid_sport` if not a known sport |
| `participants.add` | `participants: [{id, name}]` (≤ 2000) | skips existing ids and case-insensitive name duplicates |
| `participant.rename` | `participantId, name` | no-op if participant is gone |
| `participant.delete` | `participantId` | also removes it from the ranking; captures keep the id |
| `ranking.add` | `participantId` | appends if the participant exists and isn't ranked |
| `ranking.remove` | `participantId` | |
| `ranking.move` | `participantId, beforeId` (null = end) | no-op if not ranked |
| `capture.add` | `capture: {id, ts, tzOffset, participantId}` | `tzOffset` optional (minutes east of UTC, −900…900, `null` = unknown); ignored if id exists; unknown participant → unassigned; removes the participant from the ranking |
| `capture.assign` | `captureId, participantId` (nullable) | no-op if capture or participant is gone; ranking untouched |
| `capture.delete` | `captureId` | |
| `state.merge` | `state: {name, sport, participants, ranking, captures}` | non-destructive merge (see above); `sport` optional, rejected with `invalid_sport` if unknown |

Reducers are **strict about shapes** (throw an error code like `invalid_participant_name`) and
**lenient about references** (missing participants/captures → no-op), so buffered operations can
always be replayed after concurrent changes. State shape:
`{name, archived, sport, participants:[{id,name}], ranking:[participantId], captures:[{id,ts,tzOffset,participantId}]}`.
Capture order in the state is not meaningful; the UI sorts by `ts`. `sport` defaults to
`'generic'` for new races (`OperationReducer::emptyState()` / `emptyState()` in the frontend).

### Server storage
- Table `race` (`code`, `seq`, `state` JSON = materialised state) and append-only
  `race_event` (`seq`, `op_id`, `op` JSON) with unique `(race_id, seq)` and
  `(race_id, op_id)`.
- `RaceRepository::applyOperations()` per op: transaction (SQLite `BEGIN IMMEDIATE`,
  `FOR UPDATE` elsewhere) → duplicate `opId`? → archived? → reduce → insert event with
  `seq+1` → update state guarded by the old `seq`; retries on conflicts/busy database.
  Results: `applied` / `duplicate` / `rejected` (+ error).
- Plain PDO, no Doctrine. Schema lives in `Database::installSchema()` with DDL for SQLite,
  MySQL and PostgreSQL; `app:install` is idempotent. There is no migration tool yet —
  schema changes need an explicit, idempotent upgrade path.

### HTTP API (`ApiController`, CORS enabled)
`GET /api/config`, `POST /api/races`, `GET /api/races/{code}`,
`GET /api/races/{code}/events?since=N` (returns `{reset, seq, state}` when more than 500
events behind), `POST /api/races/{code}/ops` (≤ 200 ops). See README.

### WebSocket protocol (`SyncServer`)
```
client → {"type":"hello","code":"ABC123"}           server → {"type":"snapshot","code","seq","state"}
client → {"type":"ops","ops":[...]}                 server → {"type":"events","events":[{seq,op}]} (to all subscribers)
                                                    server → {"type":"presence","code","clients"} (to all subscribers)
                                                    server → {"type":"ack","opId"}  (duplicate, already applied)
                                                    server → {"type":"rejected","opId","error"}
client → {"type":"ping"}                            server → {"type":"pong"}
                                                    server → {"type":"error","error":"not_found"|...}
```
`presence` is sent to a race's subscribers whenever one joins or leaves; `clients` counts
only the WebSocket connections of *this* process, so it is a lower bound when several
WebSocket servers run, and HTTP-polling clients are never counted.

The WebSocket process also polls the database (default every 0.25 s) for events written
by the HTTP API (other PHP processes) and pushes them. It sends WS ping frames every 25 s
and drops connections idle for 75 s.

### Client (`frontend/index.html`)
- `LocalBackend` / `ServerBackend` share one interface: `getState()`, `getStatus()`,
  `dispatch(op)`, `blockReason(op)`, `pendingCount()`, `pendingCaptureIds()`,
  `clientCount()`, `destroy()`.
- All UI mutations go through `perform(op)`, which checks `blockReason` first.
- `ServerBackend`: `confirmed` + `seq` + `pending` → `view`. Server events are applied in
  `seq` order (gap → resync with a snapshot); an event whose `op.opId` matches a pending op
  settles it; `waitFor(opId)` resolves when an op is confirmed or rejected.
- Rendering is full re-render of the lists on every change (lists are small).

## Development

```bash
composer install && php bin/console app:install
php -S 127.0.0.1:8000 -t public          # PHP_CLI_SERVER_WORKERS=4 helps with polling clients
php bin/console app:websocket-server -v
node tests/reducer-parity.mjs
node tests/text-keys.mjs
node tests/e2e/offline-start.mjs   # starts its own PHP server on port 8123
BASE_URL=http://127.0.0.1:8000/ node tests/e2e/sync-smoke.mjs   # npm install first
```

`.env` defaults to `APP_ENV=prod`; use `.env.local` with `APP_ENV=dev`/`APP_DEBUG=1` for
debugging, and `php bin/console cache:clear` after changing config or service wiring.

### Checklist for adding an operation
1. Add the type to `OperationReducer::TYPES` and implement it in `apply()`.
2. Mirror it in `applyOp()` in the frontend (same validation order and error codes).
3. Decide whether it belongs to `OFFLINE_OPS` (buffered while disconnected).
4. Mark its UI controls with `data-edit="normal"` or `"critical"`.
5. Add texts (de + en) to `common` or to every sport set, extend
   `tests/reducer-parity.mjs`, run all tests.
6. Update the operations table in this file.

## Known limitations / ideas not yet requested
- No authentication: anyone who knows a race code can read and edit it.
- Offline start needs HTTPS (or localhost): on a plain-HTTP LAN address browsers don't
  run service workers; the app still works, but a reload then needs the server.
- Times come from each device's clock; a server time offset could align devices.
- Archived races can't be un-archived or deleted through the UI/API.
- The claude.ai artifact version can most likely not reach external servers (sandbox);
  server mode is meant for the page served by this backend.

# CLAUDE.md

Guidance for coding agents working on this repository. It records every requirement the
product owner has specified so far, how they are implemented, and the rules for changing
them. Read it fully before making changes; update it when requirements change.

## Product in one paragraph

A timekeeping app for the finish line of a race. A large clock shows the current time; a
button (or the space bar) records the exact time of each finish-line crossing. Participants
approaching the line together can be put into an "approaching the finish" queue in the order
they cross, so
recorded times are assigned to them automatically. Besides finishes, a time can be of another
**kind** (start, split, or a custom kind such as a protest). Data lives in the browser by default;
optionally several devices share one race through the server with real-time sync. Devices can
work at different **stations** (e.g. start line, a gate, finish line), each with its own queue
and kind. The primary device is an iPad (touch), laptops with keyboards are also used.

The app supports several sports: **generic** (the sport-neutral default), **sailing**
(regattas: participants are boats, identified by sail number or name), **running**
(participants are runners), **swimming** (participants are swimmers, a race is a meet)
and **motor sports** (participants are vehicles). That vocabulary exists **only in the
user-facing text sets**; the code itself is sport-neutral. The sport is a per-race field
(`state.sport`), picked from a select in Settings and synced like the race name.

## Product premises (apply to every new feature)

The current feature set is the MVP and forms the **core**: record times, add and rank
participants, assign times automatically (via the ranking) or manually. Every extension
must uphold these premises; if a request seems to conflict with one, raise it with the
product owner before implementing.

1. **Works local-only and synced.** Every feature must work in local mode (no server,
   including the standalone artifact copy) *and* in server mode with real-time sync across
   devices. Shared race data therefore goes through operations handled by both reducers
   (see Architecture); per-device preferences stay in `localStorage`. Decide explicitly how
   the feature behaves offline (`OFFLINE_OPS`, `data-edit` locks). A server-only or
   local-only feature is not acceptable.
2. **The core stays frictionless.** The MVP is the easiest and cleanest surface and must
   stay that way: opening the app and capturing times needs no configuration, adding and
   ranking participants stays simple, and automatic and manual assignment keep working
   without extra steps. A new feature must never add a required step, dialog, setting or
   decision in front of a core task, and its defaults must leave the core behaviour
   unchanged.
3. **Discreet, discoverable, unlockable.** The UI stays clean and focused on the key
   tasks. Non-core features are kept discreet: someone who needs one or knows what to look
   for finds it easily (settings, a panel-header icon button, a keyboard shortcut, a
   link-styled button), and using it "unlocks" the related views, columns or options —
   typically because the data it creates now exists (e.g. a column appears once some
   participant has that value), not through a separate feature toggle. As long as nobody
   uses a feature, it adds no visible panels, columns, badges or controls to the core
   screens. Prefer the existing discreet patterns (`.icon-btn`, `.link-btn`, the settings
   drawer, the shortcuts dialog) over new prominent controls.

## Vocabulary

| Concept in code | Sailing UI (de / en) |
| --- | --- |
| `race` (one timed event, has a code, name, sport, archive flag) | Regatta / regatta |
| `participant` (`{id, name}`) | Boot, Segelnummer / boat, sail number |
| `capture` (`{id, ts, tzOffset, targets, kind, worksetId}`) — one recorded finish-line crossing | Zieldurchlauf / finish |
| capture `targets` — what a capture applies to: refs `{type:'participant'\|'group', id}`; none = unassigned, several = e.g. a protest | Boot(e) / boat(s) |
| `group` (`{id, typeId, name, members}`) — participants and other groups; a capture for a group applies to its members | e.g. Flotte A / Fleet A (texts in `common`: Gruppe / group) |
| `groupType` (`{id, name, exclusive}`) — a dimension groups belong to (fleet, class, club …) | Gruppenart / group type |
| `ranking` — a workset's queue of participants approaching the line, in expected crossing order | Im Zieleinlauf / Approaching the finish |
| capture `kind` — what a capture marks: built-in `start` / `split` / `finish` or a custom kind | Start / Tonnenrundung / Zieldurchlauf, Zeitart / event type |
| custom kind (`{id, name, role}`, role `split` or `marker`) | e.g. Protest (a marker) |
| `workset` (`{id, number, name, ranking, captureKind}`) — a station: its ranking + the kind its captures get | Station / station (all sports, in `common`) |

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
src/Access/Permissions.php      Permission rules and the path each operation needs (mirrored in JS!)
src/Access/Access.php           One code's access: checks, projected state, filtered events, revocation
src/Access/AccessCodeRepository.php  Access codes: resolve, create, one per workset
src/Command/InstallCommand.php     app:install – creates tables (idempotent)
src/Command/WebSocketServerCommand.php  app:websocket-server
src/WebSocket/SyncServer.php       WebSocket protocol, subscriptions, broadcasting
src/WebSocket/ClientSession.php    Per-connection state
tests/reducer-parity.mjs|.php      JS vs PHP reducer equivalence test
tests/access-parity.mjs|.php       JS vs PHP permission rules equivalence test
tests/access-scope.php             What a restricted code sees and may do; revocation
tests/text-keys.mjs                Text sets complete in every language, all used keys resolve
tests/undo-history.mjs             Undo/redo: random sequences undone and redone restore the states
tests/e2e/sync-smoke.mjs           Playwright smoke test with two browser clients
tests/e2e/schema-version.mjs       Playwright: outdated client, older server, newer local data
tests/e2e/offline-start.mjs        PWA test: starts/stops its own PHP server, checks offline start
tools/generate-icons.mjs           Renders public/icons/*.png from the SVG definition inside it
docs/groups.md                     Design + progress: groups, capture targets, fields, schema versioning
```

`docs/` holds design documents for features in progress. Keep the one you work on up to date
(progress table, decisions, deviations); once a phase is built, its behaviour is documented
here in `CLAUDE.md`, which stays the reference for what is implemented.

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
9. **Raise `SCHEMA_VERSION`** (PHP and JS, see Schema versioning) whenever a client of the
   previous version could no longer apply the events correctly: a new state shape, a new
   operation type, changed semantics. Add a migration step on both sides; never stop
   accepting an older operation shape.

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
  - If the ranking of the device's station is non-empty, the capture is assigned to its
    **first** participant and that participant leaves that ranking (not for a marker kind, see
    Capture kinds). Otherwise the capture is stored unassigned. The capture gets the one-shot
    kind if one is armed, else the station's kind (`effectiveKind()`), and the station's id as
    `worksetId` (null while the device has no station, see Stations).
- A link-styled button under the big one ("Zeit ohne Zuordnung erfassen" / "Record a time
  without assigning it", `.link-btn`) and the key **0** (top row or numpad) record a capture
  that stays unassigned, whatever the ranking holds (`recordTime(null)`).
- That link (`#captureFreeRow`) and the assignment hints (`#hintDirect`, `#hintFree`) are
  shown **only while the station's ranking is not empty** (`renderClockCard()`): with nobody
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
  **A** / **O** / **H** the three filters · **S** cycle the sort field · **D** reverse it ·
  **K** next sticky capture kind · **Shift+K** next one-shot kind · **Escape** cancels a
  one-shot kind.
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

### Capture kinds (event types)
- Every capture has a `kind`. Built-in kinds are the sport-neutral ids `start`, `split`, `finish`
  (`BUILTIN_KINDS`, default `finish`); each sport set labels them (`kind.start` …, sailing:
  Start / Tonnenrundung / Zieldurchlauf, motor: … / Runde / …). A built-in kind is its own role.
- **Custom kinds** (`state.kinds`, `{id, name, role}`, name ≤ 40 chars, unique case-insensitively,
  also against the built-in labels in the UI) have the role `split` (a point the participants pass:
  numbered per participant, "Tor 3 2") or `marker` (an annotation such as a protest: no place, no
  delta, no elapsed time). Managed in the settings drawer between Sport and the server connection:
  rename inline, change the role, delete (✕, confirmation). Each sport set offers
  `kinds.suggestions` (`name:role, …`) as one-tap buttons; a picked suggestion is an ordinary
  custom kind with its name stored. The ops are `data-edit="normal"` (locked offline).
- A capture takes its participant out of the ranking **unless its kind is a marker**. An unknown
  kind id (a custom kind deleted, possibly concurrently) counts as a marker. Deleting a kind keeps
  its id on the captures ("(gelöschte Zeitart)" / "(deleted event type)").
- **Selected kind** (a workset's `captureKind`, `workset.setKind`): sticky and synced — every
  device on the same station records that kind until someone changes it (`stickyKind()`; finish
  without a station). Choosing a kind (segmented control, menu, **K**) needs a station, so it
  joins or creates one first (see Stations); arming a one-shot kind does not.
- **One-shot kind** (`nextKind`, per device, in memory): applies to the next capture only, then the
  sticky kind applies again. Armed via "next time only" in the kind menu, a long press (touch) or
  right-click on the segmented control, or **Shift+K**; cancelled with Escape or the note's link.
  Only a one-shot kind returns to the previous choice — a sticky choice never reverts on its own.
- **Discreet until used** (`kindsUnlocked()`: a kind other than finish selected or armed, a custom
  kind exists, or a capture isn't a finish): until then the only trace is the ⚑ `.icon-btn` in
  the clock card's tool row (menu: every kind with "next time only", plus "New event type …",
  which opens the settings). Once unlocked: a segmented control above the big button (tap =
  sticky), a kind select in every capture row. **K** cycles the sticky kind at any time.
- The button shows a kind other than finish in its label ("START ERFASSEN" / "RECORD START") and
  a stripe in the role colour (`--kind-start`, `--kind-split`, `--kind-marker`); a one-shot kind
  adds a dashed ring and a note "Next time: Protest – then back to Finish." The toast names the
  kind. The ranking title follows the sticky kind's role (`sorted.titleStart`,
  `sorted.titleSplit`, else `sorted.title`).
- **Elapsed time** of a finish = its time minus the participant's own latest start before it
  (a start targeting it directly), else the latest start of a group it is in (directly or
  through nested groups, resolved now), else the latest unassigned start before it; per
  participant for a finish of several (`captureFacts()` → `elapsedBy`, `elapsedFor()`), shown in
  the row only when it is the same for all of them. A **staggered start** is one start per group
  (e.g. per fleet); a general recall is simply a later start for the group.

### Undo / redo
- Two buttons ↶ ↷ (`.history-btns`) left of the status pill in the top bar. Like every
  top-bar control they are `--bar-h` (32 px) high on every device — the touch enlargement of
  buttons deliberately leaves them out. Discreet until
  used (premise 3): the pair is hidden while both stacks are empty and appears with the first
  undoable step; from then on either button is only disabled while its stack is empty. The
  tooltip names the step and the shortcut ("Undo: Record time (⌘Z)"); the pair is hidden when
  the race is archived. Keys follow the platform: **⌘Z / ⇧⌘Z** on Apple
  devices (`APPLE`), **Ctrl+Z / Ctrl+Y** (and Ctrl+Shift+Z) elsewhere; ⌘Y is left alone (Chrome
  history). Like the other shortcuts they are ignored while typing in inputs (the native text undo
  applies there) and while a dialog or the settings are open. Listed in the shortcuts dialog.
- Capture kinds: choosing the sticky kind (`workset.setKind`), retyping a capture and the custom
  kind operations are undoable; arming a one-shot kind is not an operation and isn't recorded.
- Stations: adding, renaming, deleting (restored in place with number, name, ranking and kind)
  and making one the default are undoable; switching this device's station is not an operation.
  An action that creates a station on the way (ranking the first participant, choosing a kind)
  is one step together with it: `perform([ops])` takes several operations as one history entry
  (`combinedEntry()`), undone in reverse order.
- Every action is undoable except `race.archive` and `state.merge` (both clear the history), as
  are resetting the local data and copying server data (not operations).
- **Compensating operations**, no reducer or server involvement: `perform()` asks
  `historyEntry(state, op)` for the operations that reverse `op` (e.g. `capture.add` →
  `capture.delete`; `participant.delete` → `participants.add` with the same id + back into the
  ranking at its old position). Undoing a recorded time puts the participants that the capture
  took out of the ranking back at their old positions (`rerankTargetsOps()`, the last one first).
  Changing a capture's targets is undone with `capture.assign` and the old `targets`. Redo re-sends the original operation. Replayed
  operations always get a fresh `opId`.
- **Conflicts are refused with a toast** (`history.undoConflict` / `history.redoConflict`) and the
  entry is dropped, so the next step continues below it. Each entry lists the state `parts` it
  touches (`name`, `sport`, `participant`, `capture`, `kind`; per workset `ranked`, `next` =
  ranking neighbour and `captureKind`; `workset` = a whole workset, `worksetName`,
  `worksetOrder`; `groupType` / `group` with their position, `groupsOfType`, `memberOf` = the
  groups containing a ref) and stores their `footprint()` before and after. Undo requires the current footprint to equal
  "after" (nobody changed those parts since) and checks on a copy (`footprintAfter()`) that the
  compensating operations really lead back to "before"; redo the other way round. The copy check
  also catches what the reducers can't restore, e.g. a capture of a deleted participant.
- Undo/redo is subject to `blockReason()` like the operations it sends (e.g. undoing a
  participant delete needs the connection); the entry then stays on the stack.
- Per device and **in memory only** (lost on reload), 50 steps (`HISTORY_LIMIT`). Only this
  device's own actions are recorded. Cleared when switching backends (connect/disconnect), when
  the local data is replaced (reset, another tab — `onReload`) and when the server rejects an
  operation. A new action clears the redo stack; no-op actions aren't recorded.

### Finishes list ("Zieldurchläufe")
- Newest first; each row shows place `#n` (by time ascending, counted within the capture's kind;
  none for markers), time, delta to the previous capture of the same kind ("First" for the
  first; none for markers) plus the elapsed time for finishes with a start, the date (spelled out only when the capture is
  not from today; the full ISO timestamp is always in the row's `title`), a participant
  dropdown to assign/reassign (`capture.assign`, replaces all participants)
  (including "(deleted participant)" if the participant was deleted) and a delete button.
- **Several participants on one capture** (a protest, a recall, a dead heat), for every kind: a
  row with a participant also has a small "+" select (`.add-target`, `capture.target.add`); a
  capture with several shows them as chips (`.target-chip`) with ✕ (`capture.target.remove`)
  plus the "+" instead of the dropdown (`buildTargetControls()`). An unassigned row has only the
  dropdown. Split numbering
  (`captureFacts().number`) only counts captures of exactly one participant. The CSV
  participant column lists all of them, comma-separated.
- Once kinds are unlocked, each row has a kind select before the participant select (its left
  border in the role colour; split kinds show their number). As soon as a capture isn't a finish
  the title becomes "Zeiten" / "Times", and with more than one kind among the captures a filter
  (`prefs.captureFilter`, per device) appears under the header.
- Captures not yet confirmed by the server show a "⏳ not synced" marker.
- Header count uses the same `.count` style as the other panels.
- Once captures come from more than one station (`stationsShown()`), the row's `title` also names
  the capture's station ("(deleted station)" for a deleted one). The station of a capture can't
  be changed.
- CSV export via the ↧ icon button in the panel header (`.icon-btn`: borderless, muted,
  right-aligned, tooltip only), in ascending order. The header is **assembled from column texts**
  (`export.col.<column>`; the participant column is per sport, e.g. sailing `Boot` / `Boat`),
  so more columns can join later: `Platz;Zeitstempel;Uhrzeit;Boot` / `Place;Timestamp;Time;Boat`.
  The *Zeitstempel/Timestamp* column is the full ISO 8601 timestamp with date and offset
  (`2026-09-25T14:33:12.45+02:00`), the *Uhrzeit/Time* column the same instant as a readable
  local time. As long as every capture is a finish that is the whole file; otherwise
  *Zeitart;Laufzeit* / *Event type;Elapsed* follow and the place column counts within each kind
  (empty for markers). With captures from more than one station a *Station* column follows.

### Participants (overall list)
- Add by name (sailing UI: sail number or boat name; max 60 chars; whitespace
  normalised). Names are unique case-insensitively; duplicates are refused with a toast.
- Rename inline (✎), delete (✕, confirmation; recorded times keep their participant id).
- CSV import via the ↥ icon button in the panel header (same `.icon-btn` style):
  one participant per line, first column (`;` or `,` separated, quotes stripped),
  an optional header line matching the text `import.headerPattern` is skipped (sailing:
  `name`, `boot`, `boat`, `segelnummer`, `sail`…), existing names are skipped.
- Each row shows the participant's latest finish (captures of role finish only), with `+x` if it
  has more, and its elapsed time when there is a start.
- Filters are toggle buttons in one bar: **All**, **No finish time**, **Hide approaching**.
  "No finish time" and "Hide approaching" can be active at the same time; clicking "All"
  turns both off; activating either turns "All" off. By default ranked participants are shown
  in the overall list too, marked with an "Approaching #n" badge and a ↩ button instead of →/✕.
  Badge, ↩/→ and "Hide approaching" refer to the ranking of the device's station.
- Sorting: **Order added / Name / Finish time** (and **Elapsed** once a start has been
  recorded, sorting like Finish time by the elapsed times) plus a direction toggle (↑/↓).
  "Finish time", "Elapsed" and the "No finish time" filter only look at finishes.
  Finish time ascending uses the participant's *first* recorded time, descending its *last*
  recorded time; participants without a time always come last. Name sort is locale-aware and
  numeric.
- Filters, sort order and language are per-device preferences (never synced).

### Groups
- **Groups** collect participants and other groups (nesting, e.g. a start group made of two
  classes); **group types** arrange them side by side (fleet *and* class *and* club), so a
  participant can be in several groups at once. Membership is **resolved when reading**, never
  stored (`groupParticipantIds()`, `resolveRefs()`, cached per render): a capture for a group
  applies to its members as they are now — moving a participant to the right fleet after the
  start gives it that fleet's start. A member that would close a cycle is skipped by the reducers.
- Managed in the settings section "Groups" (between event types and stations,
  `renderGroups()`): per type a block with its name inline, "One per member" (`exclusive`) and ✕
  (confirmation; its groups stay, without a type), its groups (name inline, "n members" link,
  ✕ with confirmation; captures keep the reference, "(deleted group)"), "+ Add group" (named
  "Group n", cursor in the name), plus "Without type" for groups that lost theirs. New types by
  name or from the sport set's `groups.suggestions` (`name:1` = one per member). The ⧉
  `.icon-btn` in the participants panel header opens the settings there.
- **Members dialog** (`#membersDialog`, `openMembers()`): participants (sorted, with the other
  group of an exclusive type as a hint) and other groups (one that contains this group is
  disabled), a search field, Apply / Cancel. Apply sends the additions and removals as one undo
  step; in an exclusive type, joining a group leaves the type's other groups in the same step.
  `exclusive` is only this UI hint — the reducers accept double membership.
- **Discreet until used:** once groups exist, participant rows show their direct groups as
  tags (`.group-tag`), a group filter (`prefs.groupFilter`, per device, members of nested groups
  included) appears above the sort bar, the capture target selects list groups in an
  `<optgroup>` per type (values are ref keys `participant:id` / `group:id`; group chips are
  dashed), and the CSV export gets one column per group type (headed by its name: the groups of
  that type the capture targets or its participants are in); the participant column names
  groups too.
- Groups can't be ranked yet (phase 4 of `docs/groups.md`): a group's start is recorded as an
  unassigned time and assigned to the group, or given to it with "+".
- Groups are participant data: seen with `participant.view` (`Access::project()`), managed
  with `groupType.add|update|delete`, `group.add|update|delete` and `group.members` (station
  codes have none); all locked offline.

### Ranking ("Im Zieleinlauf" / "Approaching the finish")
- Holds the expected crossing order of participants approaching the line together. Every
  station has its own; the panel shows the one of the device's station and is **hidden while
  the device has none** (`body.no-workset`, same grid as archived minus the clock). Its title
  doesn't name the station: that is the station pill's job (see Stations).
- → in the overall list, the quick search and **F** need a station: they join the default one
  or create the first one (`performOnWorkset()`), so ranking stays a single step.
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
- A participant is in a ranking at most once, but can be in several stations' rankings.

### Stations (worksets)
- A **workset** ("Station" in every sport, texts in `common`) holds a ranking and the kind its
  captures get. A race starts **without** any; the first is created automatically when a
  device ranks a participant or chooses a kind without having a station (the actions would have
  no home otherwise). The first workset in `state.worksets` is the **default**;
  `workset.makeDefault` (★ in the settings) moves one to the front. Deleting the default makes
  the next one the default; deleting the last one resets the race as if it never had one
  (numbering starts at 1 again).
- Unnamed worksets are called "Station {number}"; `number` is fixed when the workset is created
  (highest existing + 1), so deleting one never renames the others or the captures' stations.
  Names are ≤ 40 chars and unique case-insensitively (also against the other stations' labels
  in the UI).
- **This device's station** is per connection, never synced: `localStorage['zeitnah.workset:
  <serverUrl>|<code>']` (or `…:local`) = `{id}` with a workset id or `null` for "No station" (an
  explicit choice). It is always **explicit**: nothing falls back to the default implicitly.
  Nothing stored means the device never joined one, which only lasts while the race has no
  workset — as soon as one exists the device joins the default and stores it
  (`syncDeviceWorkset()`, before every render). An action that needs a station while the device
  is on "No station" switches it explicitly to the default (`ensureWorkset()`).
- Deleted on another device while this device is on it: the device switches to "No station"
  and a dismissible notice at the bottom of the clock card names the station and offers the
  remaining ones (`#worksetNotice`). A station this device deleted itself (including undo)
  gives no notice (`removedLocally`). None left: back to "never joined".
- Settings section "Stations" (between event types and the server connection): one row per
  station — ●/○ to use it on this device, the name inline (placeholder = "Station n"), "default"
  and the number of devices on it (WebSocket presence), ★ make default, ✕ delete (confirmation)
  — plus a "No station" row and "+ Add station" (`.link-btn`). In server mode the section is
  always there; a **local** race only shows it (and with it switching) once it has more than one
  station, e.g. after copying server data. A single local station is created silently.
- Every capture stores the `worksetId` of the device that recorded it (null without a station);
  it only leaves that station's ranking. The UI can't change it afterwards.
- **Station pill** (`#stationPill`, `renderStationPill()`), in the top bar between the status
  pill and ⚙: the device's station, or "No station" (no device count; that is in the station
  rows of the settings). Shown only once it matters — the device explicitly works without a
  station, the race has more than one (that the device sees), or the device's code is bound to
  stations (so operators can tell their devices apart) — and never in an archived race. A tap
  opens the settings at the stations. It is the only place outside the settings that
  names the current station; the status pill and the ranking title don't.
- Every station has its **own code** (see Access codes). The settings row shows it with a
  "Copy link" button to the direct link `<serverUrl>/#r=<code>`, for devices whose code lets
  them see the race's codes.

### Access codes and permissions
- Clients connect with an **access code**; the race code is one of them (everything allowed),
  and every workset gets one when it is created (`source` 'workset', `source_ref` = its id),
  which lets a device join straight into that station. All codes share one namespace and the
  race code format. Codes live on the server only (table `access_code`), never in the race
  state; local mode has none.
- A code carries **rules** (`App\Access\Permissions`, mirrored in the frontend's "Access
  rules" section): a rule grants a permission path pattern, `revoke:` takes one away again and
  always wins. Paths are segments with optional entity ids: `race.rename`, `participant.add`,
  `workset[w1].ranking.add`, `workset[w1].capture.assign`; a segment without an id matches any
  id, `*` one segment or, as the last one, everything after it (`*` alone = everything,
  `race.*`, `workset[w1].*`). `race.*, revoke:race.setSport` allows every race operation except
  changing the sport. Rules like `race[id].archive` already parse, for containers above a race
  later (a race series); today a code targets one race and race paths carry no id.
- Every operation needs one path (`operationPath()`): `race.rename|setSport|archive`,
  `race.merge`, `participant.add|rename|delete`, `kind.add|update|delete`,
  `groupType.add|update|delete`, `group.add|update|delete`, `group.members` (both membership
  operations), `workset.add`,
  `workset[W].rename|delete|makeDefault|setKind`, `workset[W].ranking.add|remove|move`, and for
  captures `workset[W].capture.add|assign|setKind|delete` (the capture's workset; changing its
  targets, `capture.target.add|remove`, needs `assign`) or
  `capture.…` for captures without one. Seeing uses `.view` paths (`race.view`,
  `participant.view`, `kind.view`, `workset[W].view`, `workset[W].capture.view`,
  `capture.view`); `access.view` lets a client see the race's codes.
- A **station code**'s rules: `race.view, participant.view, kind.view, capture.view,
  workset.capture.view, workset[W].view, workset[W].setKind, workset[W].ranking.*,
  workset[W].capture.*` — work on that station, list every capture, edit only its own station's
  captures; no renaming the race, no participant, sport, event type or station management, no
  merge, no archive, and no other station in sight.
- The server **enforces** everything: `RaceRepository::applyOperations()` rejects an operation
  the code doesn't allow with `forbidden`; snapshots are projected (`Access::project()`: other
  stations and captures out of sight removed) and events filtered (`Access::filterEvent()`: an
  event about something out of sight arrives as `{seq, opId, redacted}`, a merge as
  `{seq, opId, resync}`, upon which the client fetches a snapshot). The client mirrors the rules
  only to hide and block: `blockReason()` returns `lock.forbidden`, and elements marked
  `data-perm="<path>"` are hidden (`applyPermissions()`, `.perm-hidden`); rows built in code
  check `can(path)`. A code restricted to stations (`restrictedToWorksets()`) also hides the
  race's default station and the station help, and always shows the station pill.
- Recording needs `capture.add` on the device's station (or plain `capture.add` without one):
  a station code on "No station" doesn't see the record button, the free-capture link and the
  hints, but a hint offering its stations (`#recordBlocked`).
- **Its station deleted:** a device that may record without a station switches to "No
  station" and shows the dismissible notice; otherwise, with one station left in its scope it
  switches to it (toast), with several a modal (`#stationDialog`, `chooseStation()`) asks which
  one — it can't be dismissed without choosing.
- **Revocation:** rules are never rewritten. A code restricted to stations none of which
  exists any more is revoked (`Access::isRevokedIn()`, computed from the state); undoing the
  deletion brings the access back. `revoked_at` is for explicit revocation (not in the UI yet).
  A revoked code gets `access_revoked` (WebSocket error, HTTP 403, or as an operation's
  rejection); the client (`onAccessRevoked()`) offers unsent times as CSV
  (`revoked.*` texts), then drops the connection's cache and station and returns to local mode.
  The race stays in the recent connections list; connecting again fails with `err.revoked`
  until the station is back.
- Prepared, not built: codes created by hand (other `source` values) with other rules
  (read-only, particular permissions, a set of stations), codes for containers above a race
  (`target_type`), expiring and one-time codes (extra checks in `resolve()`; the client already
  stores the code the server returns in `access.code`, so a one-time code can later be swapped
  for a device-bound one).

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
- A borderless pushpin `.icon-btn` (inline SVG in `currentColor`, sized like the ⚑ kind button next to it) in the clock card's top row (its own flex row, so the clock
  keeps the full width) makes the card sticky at the top while scrolling; it is muted while
  inactive and in the accent colour when the clock is pinned. Pinned, the card
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
- **Server:** connect by race code (or any other access code, see Access codes) or create a
  new race (the server generates a random 6-character code from
  `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`; codes are case-insensitive, non-alphanumerics ignored).
- The server URL defaults to the server that delivered the page: the backend replaces the
  placeholder `/*SERVER_CONFIG*/null` in `index.html` with `{"serverUrl": "..."}`. It can
  be overridden under "Advanced settings" (`prefs.serverUrl`); without a default the
  field is required.
- The active connection `{serverUrl, code}` is stored in
  `localStorage['zeitnah.connection']` and resumed after a reload.
- **Recent connections** (`localStorage['zeitnah.recent']`, per device, never synced): every
  race this browser connected to, newest first, as `{serverUrl, code, name, worksetName}`
  (`worksetName` for a code bound to a station, shown as "Race (Station 2)"); the settings show
  the latest `RECENT_MAX` = 3, and while there are more a link "3 weitere anzeigen" / "Show 3
  more" (`.link-btn`, the number is what is left, at most 3) below them reveals the next 3 each
  time (`recentShown`, reset whenever the settings open). Each row has a ✕ (no confirmation) that removes the entry, so the
  next one moves up; it also deletes the race's cache and stored station unless changes are
  still waiting to be sent (then the cache stays, so reconnecting delivers them). Written on every connect and updated with the race name once it
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
  data and the code allows `race.merge`, ask whether to upload it. Yes → send `state.merge`. No, or race already has
  data → connect. The local data is always kept in the browser, whatever the answer.
  Not asked when resuming a stored connection.
- **Settings → Sync data** (server mode):
  - *Upload local data*: merge local → server; the local data stays in this browser
    (reset it explicitly in local mode if you want it gone). Merging never deletes:
    participants are matched by id or case-insensitive name, custom kinds matched by id or
    case-insensitive name (captures' kinds remapped), stations matched by id or (both named)
    name, else appended with the next number (captures' stations remapped), missing ranked
    participants appended to each station's ranking, captures added unless their id exists,
    name only set if the race has none, sport only set if the race still has the default
    (`generic`); an existing station's selected kind is not merged.
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
  `capture.assign`, `capture.delete`, `capture.setKind`, `capture.target.add`,
  `capture.target.remove`, `workset.add`, `workset.setKind`,
  `workset.ranking.add`, `workset.ranking.remove`, `workset.ranking.move` (`OFFLINE_OPS` in the
  frontend). `workset.add` is among them so the first station can be created offline.
- Everything else (rename race, add/import/rename/delete participants, custom kinds, rename /
  delete stations or change the default, merge, archive) is
  disabled until the connection is back: elements marked `data-edit="normal"` are dimmed
  via `body.lock-normal`, and `perform()` refuses with a toast.
- Status (local / connecting / connected / connection lost / update required, plus pending
  count and "Archived") is shown in a pill next to the settings button; clicking it opens settings.
- Over a WebSocket the pill also shows how many clients are on this race, after the code:
  "Server verbunden · ABC123 (5)". The count comes from the server's `presence` message
  (`backend.clientCount()`); while the HTTP fallback is in use it is unknown and omitted,
  since polling requests can't be attributed to a client. The settings panel spells the same
  number out in its status details ("2 Geräte verbunden" / "2 devices connected",
  `settings.clientsOne` / `settings.clientsMany`). Each device announces its station
  (`{"type":"workset"}`), and `presence` also counts the devices per station
  (`backend.worksetClientCount()`), shown in the settings' station rows.

### Archiving
- Settings → "Archive race" (server mode only, confirmation, irreversible).
- The server rejects every operation on an archived race (`archived`).
- Archiving empties every station's ranking (the reducers do it, so both sides agree): nothing
  is approaching the line any more.
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
- Opened by ⚙ in the top bar (next to the status pill, the station pill and the undo/redo
  buttons).
- Language · Sport (select, `data-edit="normal"`) · Event types (custom kinds, see Capture kinds) ·
  Groups (see Groups) · Stations (see Stations) · local mode: status, race code +
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
| `participant.delete` | `participantId` | also removes it from every ranking; captures keep the id |
| `capture.add` | `capture: {id, ts, tzOffset, targets, kind, worksetId}` | `tzOffset` optional (minutes east of UTC, −900…900, `null` = unknown); `targets` a list of ≤ 500 refs `{type:'participant', id}` (`invalid_capture_targets` / `invalid_capture_target`; duplicates dropped), missing/null = the legacy `participantId` (nullable); `kind` optional (`null` = `finish`, otherwise an id, else `invalid_capture_kind`); `worksetId` optional id (kept even if unknown); ignored if id exists; unknown participants dropped; removes its participants from that workset's ranking unless the kind is a marker |
| `capture.assign` | `captureId`, `targets` (list, replaces all; unknown ones dropped) or legacy `participantId` (nullable; unknown → no-op) | no-op if the capture is gone; ranking untouched |
| `capture.target.add` | `captureId, target` | appends if capture and participant exist, it isn't there yet and there are < 500; ranking untouched |
| `capture.target.remove` | `captureId, target` | |
| `capture.delete` | `captureId` | |
| `capture.setKind` | `captureId, kind` | same `kind` rules as `capture.add`; no-op if the capture is gone; ranking untouched |
| `workset.add` | `workset: {id, name?, number?, ranking?, captureKind?}`, `beforeId?` | skips an existing id and a name taken case-insensitively; name ≤ 40 (`invalid_workset_name`, null/blank = unnamed); `number` defaults to the highest + 1 (else 1…1000000, `invalid_workset_number`); `ranking` (≤ 5000 ids, `invalid_ranking`, unknown participants dropped) and `captureKind` (unknown → `finish`) and `beforeId` (insert before it, else append) restore a deleted workset (undo) |
| `workset.rename` | `worksetId, name` (null/blank = unnamed) | no-op if the workset is gone |
| `workset.delete` | `worksetId` | captures keep the id; the next one becomes the default |
| `workset.makeDefault` | `worksetId` | moves it to the front |
| `workset.setKind` | `worksetId, kind` | sets its `captureKind`; no-op unless the workset exists and the kind is built-in or an existing custom kind |
| `workset.ranking.add` | `worksetId, participantId` | appends if workset and participant exist and it isn't ranked there |
| `workset.ranking.remove` | `worksetId, participantId` | |
| `workset.ranking.move` | `worksetId, participantId, beforeId` (null = end) | no-op if not ranked there |
| `kind.add` | `kind: {id, name, role}` | built-in id → `invalid_kind_id`; name ≤ 40 (`invalid_kind_name`); role `split`/`marker` (`invalid_kind_role`); skips existing ids and case-insensitive name duplicates |
| `kind.update` | `kindId, name, role` | replaces name and role; no-op if the kind is gone |
| `kind.delete` | `kindId` | captures keep the id; resets every workset's `captureKind` that was this kind to `finish` |
| `groupType.add` | `groupType: {id, name, exclusive?}`, `beforeId?` | name ≤ 40 (`invalid_group_type_name`); `exclusive` boolean, missing/null = false (`invalid_group_type`); skips an existing id and a name taken case-insensitively; `beforeId` inserts before it (undo) |
| `groupType.update` | `groupTypeId, name, exclusive` | replaces both; no-op if the type is gone |
| `groupType.delete` | `groupTypeId` | its groups stay, with `typeId: null` |
| `group.add` | `group: {id, typeId?, name, members?}`, `beforeId?` | name ≤ 40 (`invalid_group_name`), unique case-insensitively within its type (else skipped, like an existing id); unknown `typeId` → null; `members` (≤ 5000 refs, `invalid_group_members` / `invalid_group_member`) and `beforeId` restore a deleted group (undo) |
| `group.update` | `groupId, name, typeId` | replaces both (unknown type → null); no-op if the group is gone |
| `group.delete` | `groupId` | removes it from other groups' members; captures keep the reference |
| `group.members.add` | `groupId, refs` | adds known refs that aren't members yet and don't close a cycle; members are kept sorted by `type:id` |
| `group.members.remove` | `groupId, refs` | |
| `state.merge` | `state: {schema, name, sport, participants, kinds, worksets, captures}` | non-destructive merge (see above); `schema` (missing/null = 1; otherwise an integer 1…`SCHEMA_VERSION`, else `invalid_schema`) — an older state is migrated first; `sport`, `kinds` and `worksets` optional, `sport` rejected with `invalid_sport` if unknown |

Every `workset.*` operation except `workset.add` requires `worksetId` (`invalid_workset_id`);
there is no implicit default workset in operations.

Reducers are **strict about shapes** (throw an error code like `invalid_participant_name`) and
**lenient about references** (missing participants/captures → no-op), so buffered operations can
always be replayed after concurrent changes. State shape:
`{schema, name, archived, sport, participants:[{id,name}], kinds:[{id,name,role}], groupTypes:[{id,name,exclusive}], groups:[{id,typeId,name,members:[{type,id}]}], worksets:[{id,number,name,ranking:[participantId],captureKind}], captures:[{id,ts,tzOffset,targets:[{type,id}],kind,worksetId}]}`.
States stored by earlier versions lack `kinds`, `worksets` and the captures' `kind` / `worksetId`,
and carry a race-wide `ranking` / `captureKind`: `OperationReducer::upgrade()` fills in the
former and drops the latter when the repository loads a race (a race starts without worksets),
`normalizeState()` does the same on the client.
`schema` is the state's version (see Schema versioning); states stored before versioning lack
it and count as 1. Version 2 replaced the captures' `participantId` by `targets` (migration
step 1 → 2 on both sides; old operation shapes with `participantId` are still accepted);
version 3 added `groupTypes` and `groups`, and groups as capture targets. `participant.delete`
also removes the participant from every group; `state.merge` merges group types (by id or
name) and groups (by id or type + name), unites their members and maps capture targets.
Capture order in the state is not meaningful; the UI sorts by `ts`. `sport` defaults to
`'generic'` for new races (`OperationReducer::emptyState()` / `emptyState()` in the frontend).

### Schema versioning
`SCHEMA_VERSION` (`OperationReducer::SCHEMA_VERSION`, mirrored in the frontend; the parity test
checks they are equal) versions the state shape and the operation semantics. Background and
rollout: `docs/groups.md`, "Schema versioning".
- Every state carries `schema`: the server's race state, `zeitnah.local`, the caches.
  `OperationReducer::upgrade()` (on every load) and `normalizeState()` bring a state up to date
  through the migration steps (`MIGRATIONS` / `SCHEMA_MIGRATIONS`, version → step); a state
  without `schema` is version 1 (stored before versioning), `state.merge` migrates its source.
- Clients send their version: `schema` in `hello`, `?schema=N` on every `/api/races…` request
  (added by `api()`); snapshots and `/api/config` carry the server's.
- **Client older than the server:** `client_outdated` (WebSocket error, HTTP 409), no snapshot,
  no subscription, no operations. The client (`ServerBackend.outdated()`) stops connecting,
  status "Update required", a banner (`#outdatedBanner`) with a reload button; what works
  offline (`OFFLINE_OPS`, so recording) is still buffered in the cache, everything else is
  refused with `lock.outdated`. After the reload the current page sends the buffered ops.
- **Server older than the client** (`isOlderServer()`, a snapshot without `schema` is 1): the
  client goes back to local mode with a toast (`err.serverOutdated`); the cache stays.
- **Browser data of a newer version** (`isNewerSchema()`): shown as well as possible, never
  written (`localStore.tooNew`, `ServerBackend.cacheTooNew`), every operation, reset, upload and
  "copy to this browser" refused, the banner asks for a reload. `outdatedReason()` on both
  backends (`'client'` / `'storage'` / null) drives the banner.
- Reducers accept older operation shapes forever, so ops buffered by an old page replay.

### Server storage
- Table `race` (`code`, `seq`, `state` JSON = materialised state) and append-only
  `race_event` (`seq`, `op_id`, `op` JSON) with unique `(race_id, seq)` and
  `(race_id, op_id)`.
- Table `access_code` (`code` unique, `target_type` 'race', `target_id`, `rules` JSON,
  `source` 'race' | 'workset', `source_ref`, `created_at`, `revoked_at`), unique
  `(target_type, target_id, source, source_ref)`. `app:install` creates it and gives every
  existing race its race code as a full-access row (idempotent). `race.code` stays as the race
  code, but lookups go through `access_code` (`AccessCodeRepository::resolve()`).
- `RaceRepository::applyOperations()` per op: transaction (SQLite `BEGIN IMMEDIATE`,
  `FOR UPDATE` elsewhere) → duplicate `opId`? → archived? → code revoked? → allowed? → reduce
  → insert event with `seq+1` → update state guarded by the old `seq` → codes for new worksets
  (`workset.add`, `state.merge`); retries on conflicts/busy database.
  Results: `applied` / `duplicate` / `rejected` (+ error, e.g. `forbidden`, `access_revoked`).
- Plain PDO, no Doctrine. Schema lives in `Database::installSchema()` with DDL for SQLite,
  MySQL and PostgreSQL; `app:install` is idempotent. There is no migration tool yet —
  schema changes need an explicit, idempotent upgrade path.

### HTTP API (`ApiController`, CORS enabled)
`GET /api/config`, `POST /api/races`, `GET /api/races/{code}`,
`GET /api/races/{code}/events?since=N` (returns `{reset, seq, state}` when more than 500
events behind), `POST /api/races/{code}/ops` (≤ 200 ops). See README. Race requests carry
`?schema=N`; an older client gets 409 `client_outdated`. `{code}` is any access
code; snapshots and events are projected/filtered for it and carry
`access: {code, rules, codes?}`; unknown codes are 404 `not_found`, revoked ones 403
`access_revoked`.

### WebSocket protocol (`SyncServer`)
```
client → {"type":"hello","code":"ABC123","schema"} server → {"type":"snapshot","code","schema","seq","state","access"}
client → {"type":"workset","worksetId"}             (the device's station, null = none; only for presence)
client → {"type":"ops","ops":[...]}                 server → {"type":"events","events":[{seq,op}]} (to all subscribers, filtered per code)
                                                    server → {"type":"access","code","rules","codes"?} (after worksets were added/deleted)
                                                    server → {"type":"presence","code","clients","worksets":{id:n}} (to all subscribers)
                                                    server → {"type":"ack","opId"}  (duplicate, already applied)
                                                    server → {"type":"rejected","opId","error"}
client → {"type":"ping"}                            server → {"type":"pong"}
                                                    server → {"type":"error","error":"not_found"|"access_revoked"|"client_outdated"|...}
```
Subscribers are grouped by race (any of its codes). After a `workset.add`, `workset.delete` or
`state.merge` event every subscriber's access is checked again: a revoked code gets
`access_revoked` and is unsubscribed, the others get `access` (with fresh codes if they may
see them).
`presence` is sent to a race's subscribers whenever one joins, leaves or switches its station
(`worksets` counts the devices per station); `clients` counts
only the WebSocket connections of *this* process, so it is a lower bound when several
WebSocket servers run, and HTTP-polling clients are never counted.

The WebSocket process also polls the database (default every 0.25 s) for events written
by the HTTP API (other PHP processes) and pushes them. It sends WS ping frames every 25 s
and drops connections idle for 75 s.

### Client (`frontend/index.html`)
- `LocalBackend` / `ServerBackend` share one interface: `getState()`, `getStatus()`,
  `dispatch(op)`, `blockReason(op)`, `pendingCount()`, `pendingCaptureIds()`,
  `clientCount()`, `worksetClientCount(id)`, `isReady()`, `announceWorkset(id)`,
  `accessInfo()` (`{code, rules, codes?}`; local: everything), `outdatedReason()`, `destroy()`. `ServerBackend`
  also caches its access and takes the snapshot fetched while connecting (`seed()`).
- All UI mutations go through `perform(op | [ops])`, which checks `blockReason` first.
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
node tests/access-parity.mjs
php tests/access-scope.php
node tests/text-keys.mjs
node tests/undo-history.mjs
node tests/e2e/offline-start.mjs   # starts its own PHP server on port 8123
BASE_URL=http://127.0.0.1:8000/ node tests/e2e/sync-smoke.mjs   # npm install first
BASE_URL=http://127.0.0.1:8000/ node tests/e2e/schema-version.mjs
```

`.env` defaults to `APP_ENV=prod`; use `.env.local` with `APP_ENV=dev`/`APP_DEBUG=1` for
debugging, and `php bin/console cache:clear` after changing config or service wiring.

### Checklist for adding an operation
0. Check the feature against the product premises (local + synced, core untouched,
   discreet until used).
1. Add the type to `OperationReducer::TYPES` and implement it in `apply()`. A new type (or a
   changed state shape) raises `SCHEMA_VERSION` on both sides — see Schema versioning.
2. Mirror it in `applyOp()` in the frontend (same validation order and error codes).
3. Decide whether it belongs to `OFFLINE_OPS` (buffered while disconnected).
4. Mark its UI controls with `data-edit="normal"` or `"critical"`, and with `data-perm`.
4a. Give it a permission path in `Permissions::operationPath()` and the JS mirror (extend
   `tests/access-parity.mjs`), and decide in `Access::filterEvent()` who may see its events.
5. Make it undoable: a case in `historyEntry()` (compensating ops + the `parts` it touches) and
   a label in `HISTORY_LABELS`, extend `tests/undo-history.mjs` — or add it to
   `HISTORY_BARRIER_OPS` if it can't be undone.
6. Add texts (de + en) to `common` or to every sport set, extend
   `tests/reducer-parity.mjs`, run all tests.
7. Update the operations table in this file.

## Known limitations / ideas not yet requested
- Groups can't be put into a ranking yet, participant metadata fields and rule-based groups
  don't exist yet: phases 4 and 5 of `docs/groups.md`.
- No authentication: anyone who knows a code has what its rules grant; there is no UI yet to
  create codes by hand, change their rules or revoke them explicitly.
- Offline start needs HTTPS (or localhost): on a plain-HTTP LAN address browsers don't
  run service workers; the app still works, but a reload then needs the server.
- Times come from each device's clock; a server time offset could align devices.
- Archived races can't be un-archived or deleted through the UI/API.
- The claude.ai artifact version can most likely not reach external servers (sandbox);
  server mode is meant for the page served by this backend.

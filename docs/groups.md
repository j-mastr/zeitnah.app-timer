# Design: groups, capture targets and participant fields

Status: **accepted concept, implementation in progress** — see [Progress](#progress).
Keep this document up to date while working through the plan: tick off phases, record
decisions and deviations, and move what is built into `CLAUDE.md` (which stays the reference
for implemented behaviour).

## Goal

Support staggered starts and everything that needs participants in collections:

- a start time recorded for a whole group (e.g. a sailing fleet) applies to every member;
- a capture can concern several participants (a protest, a recall, a dead heat);
- collections are flexible enough for the usual setups of every sport, including parallel
  membership (fleet *and* class *and* club) and several levels (a start group made of classes);
- participants carry custom metadata (e.g. a yardstick value) that later reports use and that
  can decide group membership dynamically.

## Where the current model falls short

| Place | Limitation |
| --- | --- |
| `capture.participantId` | exactly one participant; a capture can't name a group or several participants |
| `captureFacts()` elapsed rule | own start, else the *latest unassigned* start — wrong for all but the last group of a staggered start |
| `participants: [{id, name}]` | no metadata, no grouping |
| `workset.ranking: [participantId]` | a whole fleet can't be queued for its start signal |
| Access rules | stored per code and never rewritten, so a new `*.view` path would not reach existing station codes |
| Clients | `applyEvents()` skips events it can't apply with a `console.warn`, and unknown WebSocket errors are ignored: an old client would silently diverge from a new state shape |

Race data is a JSON column, so the server needs no schema migration of its tables:
`OperationReducer::upgrade()` / `normalizeState()` convert states, as they did for stations.

## Options considered

| Approach | Parallel membership | Multi-level | Complexity | Verdict |
| --- | --- | --- | --- | --- |
| A. Strict hierarchy (`participant.parentId`, fixed levels) | no | yes | low | too rigid: a boat is in a fleet *and* a class; several classes often share a start |
| B. Generic typed entities (custom participant types, arbitrary relations) | yes | yes | very high | rewrites every participant touch point in both reducers, undo, merge, projection, CSV, lists; endangers premise 2 |
| **C. Participants stay the timed leaves; one generic `group` entity with nestable members, group types as parallel dimensions, rule-based membership from fields; captures target any mix of participants and groups** | yes | yes (groups contain groups) | medium | **chosen** |

Participants remain the only thing a time belongs to; groups only decide *which*
participants a capture applies to.

## Model

### State additions

```
schema:      N                                        schema version of the state (see Schema versioning)
groupTypes:  [{id, name, exclusive}]                  e.g. "Fleet" (exclusive), "Class", "Club"
groups:      [{id, typeId, name, members:[ref], rule}]
fields:      [{id, name, type}]                       type: text | number | choice (+ options for choice)
participants:[{id, name, meta:{fieldId: value}}]
captures:    [{id, ts, tzOffset, targets:[ref], kind, worksetId}]   replaces participantId
worksets:    [{…, ranking:[ref]}]                     refs instead of participant ids (phase 3)

ref  = {type: 'participant' | 'group', id}
rule = {all: [{fieldId, op: 'eq' | 'in' | 'range', value | values | min, max}]} | null
```

- **Typed refs** keep participant and group ids apart and leave room for future target types
  (a station, a race of a series) without another migration.
- **Members are stored on the group** (like a workset's ranking): `participant.delete` removes
  the participant from every group as it does from every ranking; membership ops are set
  operations, so concurrent edits don't overwrite each other.
- **Fields have no role.** A field is only `{id, name, type}`; nothing marks it as a yardstick
  or handicap. See [Picking a field for a calculation](#picking-a-field-for-a-calculation).

### Resolution (decided: on read)

`resolve(ref)` → set of participant ids: a participant ref is itself; a group ref is the union
of its explicit members (recursively) and every participant whose `meta` matches its `rule`.
A visited set makes it cycle-safe. Resolution happens when reading, never when writing: moving
a boat to the right fleet after the start gives it that fleet's start time. This corrects
mistakes and keeps the operations small.

- Nesting covers hierarchies: sailing start group "Wave A" = classes {ILCA 7, 420}; running
  "10 km" → age groups; motor sport class → team.
- Group types cover parallel memberships.
- **Double membership is acceptable.** `exclusive` is only a UI hint (single-select chip in
  the participant row, "move" = remove + add as one undo step); the reducers never enforce it
  and the UI shows no warning.
- Concurrent ops may form a cycle (G1 into G2 while G2 goes into G1): the reducer skips a
  member ref that would close a cycle (lenient, no error), and resolution is cycle-safe anyway.

### Capture semantics

| Capture | Applies to |
| --- | --- |
| start / finish / split targeting a group | every resolved member |
| several targets | every target (protest on A and B, OCS recall on three boats, dead heat) |
| marker | as today: no place, no delta, no elapsed time; shown on every target |
| split numbering | only when the capture resolves to exactly one participant |
| place | unchanged: one place per capture within its kind (dead-heat places are a reporting question, postponed) |

**Elapsed time** of a finish: the latest start before it that applies to the participant —
its own start first, then a start of any group it belongs to, then an unassigned start.
A general recall is simply a later start for the fleet.

`capture.add` removes each target ref from the station's ranking (not for markers); a group
start removes the group ref, not its members.

### Operations

| type | fields | notes | offline |
| --- | --- | --- | --- |
| `capture.add` | `capture.targets` (also still `capture.participantId`) | legacy shape accepted forever | yes |
| `capture.assign` | `captureId`, `targets` (list) or legacy `participantId` | replaces all targets | yes |
| `capture.target.add` / `.remove` | `captureId, target` | commutative list edits | yes |
| `groupType.add` / `.update` / `.delete` | | | locked |
| `group.add` / `.update` / `.delete` | `add` restores members and position (undo) | `delete` removes it from other groups' members and from rankings; captures keep the ref ("(deleted group)") | locked |
| `group.members.add` / `.remove` | `groupId, refs` | set semantics; unknown refs skipped; cycle-closing refs skipped | locked (decided) |
| `field.add` / `.update` / `.delete` | | | locked |
| `participant.setMeta` | `participantId, fieldId, value` | one field per op, no collisions between fields | locked |
| `state.merge` | + `groupTypes`, `groups`, `fields`, `meta` | groups by id or (type, name), fields by id or name, meta only where empty, members united via the id map, capture targets remapped; the incoming state is migrated to the current schema first | locked |

Limits (new error codes): ≤ 500 groups, ≤ 5000 members per group, ≤ 500 targets per capture,
≤ 30 fields, meta values ≤ 80 chars.

### Permissions

- Seeing groups, group types and fields follows the existing `participant.view` (they are
  participant data) — existing station codes, whose rules are never rewritten, see them
  without a migration.
- Editing: new paths `group.add|update|delete|members`, `groupType.*`, `field.*`,
  `participant.setMeta`. Station codes don't get them (they may not manage participants
  either). Capture targets stay under `workset[W].capture.assign` / `capture.assign`.
- Prepared: `group[G]` scopes (a code that only sees fleet B).

### UI (premise 3: discreet until used)

- Nothing changes until the first group or field exists.
- Entry: a `.icon-btn` in the participants panel header and "Groups & fields" in the settings,
  with per-sport suggestions (`groups.suggestions`, like `kinds.suggestions`): sailing Fleet,
  Class, Start group · running Wave, Age group, Distance · swimming Heat, Event · motor Class,
  Team.
- Unlocked once they exist: group chips on participant rows, a group filter, groups in the
  quick search and in the capture target select (`<optgroup>`), "+" on marker rows for more
  targets, a CSV column per group type and per field.
- Rule-based groups: "Create groups from the values of *Class*" makes one group per distinct
  value with an `eq` rule; yardstick bands use `range` rules.
- CSV import: extra columns with a header row become fields + meta values; the first column
  stays the name, so the core import is unchanged.

### Picking a field for a calculation

Calculations (reporting, later) that need a handicap-like value never rely on a field role:

1. Candidates are the race's `number` fields.
2. Their names are compared case-insensitively against typical names — a text key per sport
   and language (e.g. `calc.handicapFieldNames`: sailing `yardstick, ys, handicap, rating,
   tcf, phrf, gph, …`), so no sport terms enter the code.
3. Exactly one match: use it and name it in the result header ("corrected by *Yardstick*"),
   changeable there.
4. None or several: ask the user to choose; never calculate on a guess between several.

Where the choice is remembered belongs to the reporting design.

## Schema versioning (decided)

Needed because old clients silently skip events they can't apply.

**Model**
- `SCHEMA_VERSION` (integer) in `OperationReducer.php` and the frontend; the parity test checks
  they are equal.
- Every state carries `schema: N`: the server race state, `zeitnah.local`, `zeitnah.cache:*`.
- The version is raised whenever an older client could no longer apply the events correctly:
  a new state shape, a new operation type, changed semantics.
- `upgrade()` / `normalizeState()` become a chain of migration steps (1→2, 2→3, …), mirrored in
  both languages and covered by parity fixtures.
- Reducers keep accepting legacy operation shapes forever (`capture.add` / `capture.assign`
  with `participantId`), so ops buffered by an old page can always be replayed.

**Protocol**
- The client sends its version in `hello` (`{type:'hello', code, schema}`) and as `schema` with
  HTTP requests; snapshots carry the server's `schema`.
- Client older than the server: snapshot and ops are refused with `client_outdated`. The client
  keeps its pending ops and shows a notice with a reload button; after the reload the new page
  migrates the cache and sends them. Ops are refused too, because they were decided on a
  misread view (a legacy `capture.assign` would replace a protest's whole target list).
- Client newer than the server (server URL overridden to an older server): the client refuses
  with `server_outdated` and stays local.
- Local storage with a newer schema than the page (old page from the service worker cache,
  another tab updated): the page doesn't write and shows the reload notice — never downgrade
  stored data.
- `state.merge` migrates the incoming state to the current schema first.

**Rollout:** versioning ships as its own release **before** capture targets, so clients in the
field understand `client_outdated`. Pre-versioning clients (no `schema` in `hello`) count as
version 1 and are refused once the server is at 2; they ignore the error, show a stale view and
keep their ops in `localStorage` until the next reload delivers them. The service worker is
network-first, so this only affects pages left open across the release.

## Scenarios

| Scenario | Model |
| --- | --- |
| Sailing fleets, staggered start | group type Fleet (exclusive); rank "Fleet A", Space → start targets fleet A |
| General recall | another, later start for fleet A |
| Individual recall / OCS | marker kind "OCS" targeting the boats |
| Protest | marker targeting several boats |
| Several classes in one start | group "Wave 1" with the class groups as members |
| Yardstick handicap | number field "Yardstick"; groups by `range` rule; corrected time from elapsed time and the field picked as above |
| Running waves, age groups | wave by explicit list or a bib range rule; age group by a birth-year rule; parallel |
| Swimming heats | one group per heat, one start per heat |
| Motor sport class / team | two group types in parallel |

## Decisions

| # | Question | Decision |
| --- | --- | --- |
| 1 | Schema versioning to protect old clients | yes, see above |
| 2 | Resolve group membership on read | yes |
| 3 | Membership edits offline | locked; can be corrected later |
| 4 | Double membership in an exclusive type | acceptable, no warning |
| 5 | Places of a dead heat / multi-target finish | postponed (reporting) |
| 6 | Field roles (e.g. yardstick) | none; calculations guess by name or ask |

## Plan

Each phase ships on its own and follows the "Checklist for adding an operation" in `CLAUDE.md`.

0. **Specification** — this document; `CLAUDE.md` points to it.
1. **Schema versioning** — `SCHEMA_VERSION`, `schema` in states, migration chain, `hello` / HTTP
   parameter, `client_outdated` / `server_outdated` and the reload notice, the guard against
   newer local data, parity fixtures. Released first, still at version 1.
2. **Capture targets** (schema 2) — `participantId` → `targets`, legacy op shapes,
   `capture.target.add/remove`, merge remapping, undo parts, `captureFacts()` via a memoised
   `resolvedTargets(c)`, sorting, participant rows, CSV; "+" on marker rows.
3. **Groups and group types** — ops in both reducers, cycle-safe `resolve()` in JS,
   `participant.delete` clean-up, merge, permissions, undo (a participant delete restores its
   memberships), the new elapsed rule; UI: manager, chips, filter, groups in selects/search,
   export columns.
4. **Groups in rankings** — `ranking` entries become refs (drag & drop, keycaps, shortcuts,
   badges): "rank a fleet, press Space".
5. **Fields and rule-based groups** — `fields`, `participant.setMeta`, CSV import with columns,
   rule groups, "create groups from values"; a PHP `resolve()` with a parity test once the
   server needs it.
6. **Reporting** (prepared, not built) — field picking as above, corrected times, results per
   group, dead-heat places, group-scoped codes, stations bound to a group.

## Progress

| Phase | State | Notes |
| --- | --- | --- |
| 0. Specification | done | this document |
| 1. Schema versioning | done (version 1) | see "Phase 1 notes"; must be deployed before phase 2 ships |
| 2. Capture targets | done (version 2) | see "Phase 2 notes"; deploy only after phase 1 has been live for a while |
| 3. Groups and group types | open | |
| 4. Groups in rankings | open | |
| 5. Fields and rule-based groups | open | |
| 6. Reporting | not planned yet | |

### Phase 1 notes

Built as designed; `CLAUDE.md` ("Schema versioning") documents the implementation. Details
decided while building it:

- **Recording goes on while outdated.** A client refused with `client_outdated` still accepts
  what works offline (`OFFLINE_OPS`: recording, assigning, rankings); those ops stay buffered
  in the cache and are sent by the reloaded page. Everything else is refused with
  `lock.outdated`. Stopping the recording at the finish line was not an option.
- New status `outdated` ("Update required") in the status pill; a banner with a reload button
  (`#outdatedBanner`) for an outdated client and for browser data of a newer version.
- A server older than the client sends the device back to local mode (toast); its cache stays.
- Browser data of a newer version blocks every operation plus reset, upload and "copy to this
  browser"; it is shown, never written.
- `normalizeState()` treats a `schema` below 1 as 1 (nothing predates version 1); `state.merge`
  rejects it (`invalid_schema`), like any value that isn't an integer 1…`SCHEMA_VERSION`.
- Migration steps are keyed by the version they upgrade from (`MIGRATIONS` in PHP: method names,
  `SCHEMA_MIGRATIONS` in JS: functions). In JS they run on the *raw* state before
  `normalizeState()` sanitises it, so a step must cope with the legacy field names too.
- Tests: `tests/reducer-parity.mjs` checks the constants are equal, legacy states come out at
  the current version, and `state.merge` with valid and invalid `schema` values;
  `tests/e2e/schema-version.mjs` simulates an older and a newer app version by rewriting
  `SCHEMA_VERSION` in the served page.
- **Remaining risk:** a page from before versioning that is left open (or started offline from
  the service worker cache) doesn't know `schema`; if it writes local data after a newer page
  stored version-2 data, it downgrades it. Phase 2 should therefore ship some time after
  phase 1 is deployed.

### Phase 2 notes

Built as designed, participants only (`TARGET_TYPES = ['participant']`; a `group` ref is
rejected with `invalid_capture_target` until phase 3 raises the version again).
`CLAUDE.md` documents the operations and the UI. Details decided while building it:

- **`capture.assign` takes a `targets` list** (replacing all targets, unknown ones dropped)
  instead of a single `target` as planned. Undo of every target change needs to restore a whole
  list, and one op does that. The legacy `participantId` form keeps its exact old semantics
  (an unknown participant makes it a no-op).
- `capture.target.add` / `.remove` need the `assign` permission (`workset[W].capture.assign`);
  no new permission path, so existing station codes keep working unchanged.
- Duplicates in a target list are dropped (first occurrence wins); at most 500 targets.
- **Elapsed time** is computed per participant (`elapsedBy`); a row shows it only when it is the
  same for all of its participants. A start with several targets is the own start of each.
- **UI:** a single-participant row is unchanged (the select replaces the assignment). The "+"
  select appears only on marker rows with a participant and on rows with several; several are
  shown as chips with ✕. The row's controls wrap, so the chips get a line of their own on
  narrow screens.
- Undo of a capture of several ranked participants re-ranks them last-first, so each is moved
  before a neighbour that is already back.
- Limitation of the parity test: objects with keys `0…n` (e.g. `{"0": ref}`) decode as lists in
  PHP; the generator doesn't send them (no real client does).
- `tests/e2e/offline-start.mjs` now announces the server's schema when it fetches a snapshot.

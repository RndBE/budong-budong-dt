# Working in this repository

Dam monitoring dashboard (Laravel 13 + Blade/Alpine/Tailwind v4). Read `README.md`
first for the feature map, data model and telemetry integration; this file only
records conventions that are easy to break.

## Language

- UI strings, seeded data, and `README.md` are Indonesian.
- Code, comments, commit messages, and this file are English.
- Status vocabulary is fixed: `normal`, `waspada`, `siaga`, `bahaya`, `offline`
  (see `config/dam.php`). Buckets shown in the health donut are `aman`,
  `waspada`, `siaga`, `bahaya`.

## Data flow

- Every value the UI renders comes from `App\Services\MonitoringService`, which
  reads through the `TelemetryProvider` contract. Do not query `sensor_readings`
  from a controller or a Blade view.
- Refraction near the horizon is NOAA's piecewise fit in `SolarClock`. The naive
  `1 arcmin / tan(elevation)` form returns +19 degrees for a sun on the horizon,
  which used to flash full daylight across the stage at sunrise and sunset;
  `test_the_sun_climbs_without_jumping_over_the_horizon` guards that.
- Timestamps are stored in UTC and formatted with the dam's timezone
  (`Asia/Makassar`). When converting, keep the converted instance in a variable —
  the models return immutable Carbon instances, so `->setTimezone()` does not
  mutate in place.
- Thresholds live in `sensor_metrics`; `SensorMetric::statusFor()` is the single
  place that maps a value to a status.

## Frontend

- Alpine state lives in two stores: `site` (environment, markers, dashboard,
  clock) and `viewer` (open panorama + selected station). Pages read from the
  stores rather than receiving their own props.
- Never store a Photo Sphere Viewer instance on Alpine component state; keep it
  in a closure object (`psv`). Alpine's Proxy throws on three's read-only matrix
  properties, which the viewer uses internally.
- The stage is one viewer, not two: `twin-sphere.js` owns the sphere and swaps
  its panorama between the base dam view (station pins) and a station view
  (hotspots). `panorama.js` is only the PSV loader plus markup helpers. Never
  mount a second viewer for the station view — that is a second WebGL context
  for the same picture.
- The compass keeps the stage's top-left corner in both views, so the station
  banner starts at `calc(var(--stage-left) + var(--compass-w))`. `--compass-w`
  is 0 below `sm`, where the compass is hidden.
- Chrome that covers the stage must be `pointer-events-none` on its root with
  `pointer-events-auto` on the controls themselves (`station-overlay`), or it
  eats every drag meant for the sphere. Drag speed is tuned per viewport in
  `tuneMoveSpeed()`: the viewer scales a drag by the vertical field of view over
  the container height, so narrow stages need a higher `moveSpeed` to stay
  one-to-one with the pointer.
- Every step of an arrival moves the same way. The approach turns to the pin
  and the cross-fade swaps the picture; both ends sit at the resting framing
  from `dam.stage.default_zoom`, which the stage opens at (0 — its widest). Any
  zoom written as a level rather than derived from that resting value is a lean
  *in* where the choreography says stand still: `restingZoom()` and
  `closerZoom(step)` exist so nothing hard-codes 45 again. Leaning in during the
  approach and giving that zoom back on arrival is what read as walking
  backwards.
- `loadHd()` passes the current zoom through: `setPanorama` without one falls
  back to the viewer's default and yanks the camera out of whatever framing the
  reader had.
- The cross-fade into a station must not rotate (`rotation: false`): the camera
  has just been turned to the pin, so swinging it to the new panorama's default
  yaw mid-fade is the jump that reads as a blink. The pins fade out with
  `sphere--departing` first; blanking them a frame early looks like a flicker.
- Never let the chrome wait on `setPanorama`'s promise alone — race it against a
  timeout. A throttled tab can leave that promise pending long after the picture
  changed, which strands `flying` and hides the station banner for good.
- Switching panorama is an animation, not a loader: `pointAt()` turns the camera
  to the pin and leans in (pins stay up while `pointing` is true), then
  `setPanorama` cross-fades with `rotation: true` and lands zoomed-in before
  easing back to the resting zoom. Warm the texture with
  `viewer.textureLoader.preloadPanorama()`, never with an `Image` of your own —
  the viewer keeps its own cache and would fetch the file twice.
- The stage drifts by itself (autorotate, `autostartOnIdle`); the rotate button
  must clear that plugin option as well as calling `stop()`, or the drift creeps
  back after the idle delay. It is a plugin option — `psv.autorotate.setOption`,
  not `viewer.setOption`.
- The base panorama opens on a compass bearing, not a raw yaw
  (`dam.stage.default_bearing`, 36 = north-east across the dam):
  `openingYaw()` subtracts `north_offset`, so correcting where north sits does
  not swing the opening framing. Stations still open on their own
  `panorama_yaw`, and the reset control uses the same helper.
- `position-updated` and `zoom-updated` only fire on a *change*, so the
  mirrored camera state is seeded from `viewer.getPosition()` /
  `getZoomLevel()` in the `ready` handler. Without it the compass read north
  while the camera looked north-east, and the zoom readout claimed 100% before
  anyone touched the stepper.
- That drift sweeps, it does not circle: `driftAround()` gives the plugin two
  keypoints either side of the panorama's own framing (`dam.stage.drift_arc`),
  so it walks right, then back left along the short arc. A full turn eventually
  reaches the seam where the render was joined — the one part of the picture
  nobody should be shown. Keypoints are set through `setKeypoints()`, never
  `setOption('keypoints')`, which the plugin rejects, and the sweep is
  re-centred on every arrival. It sets off to the right because the right-hand
  keypoint is first and the plugin has `startFromClosest: false` — with the
  default it would pick whichever side it happened to be nearest.
- Nothing that moves every frame may carry `backdrop-filter`. The pin captions
  are deliberately flat plates rather than `.glass`: fifteen blurred chips
  travelling with the sphere is what makes the drift stutter on a big screen.
- The viewer is built asynchronously, so `show()` can be called before it
  exists (deep link `/digital-twin/{station}`, or a click during startup). Such
  a request is parked in `psv.pending` and replayed on `ready` — dropping it
  leaves the stage on the base panorama while the chrome claims a station.
- Station pins are placed by `sphere_yaw`/`sphere_pitch`; while those are null
  `MonitoringService::spherePosition()` derives a bearing from the plan-view map
  percentages (tunable in `dam.stage.sphere`). A drop posts to
  `/api/stations/{code}/sphere`.
- `<template x-for>` does not work inside `<svg>`. Build SVG fragments as a
  string in the store and inject with `x-html` (see `site.healthDonut`).
- Heavy libraries (photo-sphere-viewer with its three.js, echarts) must stay
  behind dynamic imports so the entry bundle stays small.
- Analytics is one screen with two views, `grafik` and `analisa`: a chart per
  parameter (all of a station's, or the headline parameter of every station
  when `scope = 'semua'`), and the parameters the reader ticks drawn into a
  single chart. A card in the grid is a shortcut into `analisa` — never a
  second menu entry, which would duplicate the station and range controls and
  make the reader choose before they can look. Station, range and mode persist
  in `localStorage` (an older stored `kisi`/`detail` still resolves).
- One range governs the whole grid. Per-card ranges would put charts side by
  side that cannot be compared, which is the only reason to place them there.
- Grid cards draw when they scroll into view (one `IntersectionObserver`,
  `rootMargin` 160px): sixteen charts built at once is a frozen second for rows
  nobody has reached. The observer only reports a *change*, so returning from
  `analisa` — where every card was `display: none` — calls `drawInView()`,
  which measures rather than waiting for a scroll that may never come.
- `analisa` combines units through a second y-axis, and stops at two. Each axis
  carries its unit as its name; a third scale on one chart is a picture nobody
  can read, so those chips are rendered disabled with the reason in the title.
  Thresholds are drawn only when a single parameter is picked — every set at
  once turns the chart into a ladder. Statistics describe the first parameter
  picked and say so.
- `seriesChart`/`sparkline` attach a `ResizeObserver` to their container.
  ECharts measures at `init`, and a container that was `display: none` measures
  zero — the library then keeps a 100px canvas for ever, which is how the
  detail chart came out a sliver of its panel. Never rely on a `resize()` call
  placed after the fact.
- Destructure a dynamic import on its own line — never collect the namespaces
  through `Promise.all` and read `components.GridComponent` off them. That is a
  dynamic property access, so Rollup keeps every export: the chart chunks were
  953 kB (a quarter of that GeoJSON parsing no chart here asks for) and became
  227 kB the moment each `await import(...)` was destructured where it stands.
- Navigation is server rendered, so the chrome tells the reader a click landed:
  `.nav-progress` starts on any same-origin link click or form submit and dies
  with the document. It never reports 100% — the next page arriving is the
  completion. It also stops the polls: a dev server answers one request at a
  time, and a refresh nobody will read would be ahead of the page in the queue.
- The layout carries a `speculationrules` script so Chromium builds the next
  page on hover. Prerender, not prefetch: these pages answer `no-cache`, which
  a prefetched copy may not be reused for. The twin is excluded — a background
  WebGL sphere for a page nobody opened is not a saving. A prerendered document
  runs its scripts before anyone sees it, so `whenPageIsActive()` holds the
  polls until `prerenderingchange` (or runs them straight away when the page
  was opened normally).
- The digital twin page (`/digital-twin`, route name `twin`) is the interactive
  stage: the dam's own 360 panorama with a pin per station, swapped for that
  station's panorama when a pin is picked. There is no separate map page;
  `/peta` only redirects.
- The compass reads `bearing = heading + northOffset`, but the dial rotates by
  `-dialAngle` — an unwrapped running total. Feeding it the 0-360 bearing makes
  a CSS transition unwind 359 degrees backwards every time the camera passes
  north. The case and its top marker stay fixed. `north_offset` comes
  from the panorama payload (`panorama_north_offset`, falling back to
  `dam.stage.north_offset`) — the renders are not oriented, so it is data, not
  something to derive.
- Every `viewer.animate()` call must be wrapped in `settle()`: a camera move
  that another move cancels rejects with `isFromCancelledTransition`, which
  otherwise lands in the console as an unhandled rejection.
- Focus is visible app-wide through one `:focus-visible` rule in `app.css`
  (bright ring plus a dark halo, because the chrome floats over a photograph).
  Never add `focus:outline-none` — that is what made the keyboard invisible in
  the first place.
- A bare `<input>` inside a glass pill must not carry the focus ring itself: the
  outline draws a hard rectangle inside a rounded chip. `app.css` moves the ring
  to the pill with `:has(> input:focus-visible)` (behind `@supports selector`,
  so older browsers keep the plain ring).
- `.glass-button` is for a single control: it applies `gap-2`, so putting it on
  a *container* (the zoom stepper) inserts 8px around every child. Containers get
  `.glass .glass--chip` only.
- Icon-only controls carry `aria-label` as well as `title`; a tooltip is not a
  name for touch or a screen reader. Toggles also carry `aria-pressed`.
- The bottom bar pills filter the pins (`pinFilter` in `twin-sphere.js`, water
  types listed in `waterTypes`). They used to dispatch an event nobody listened
  to — controls that look live must do something.
- A filter with more than a handful of options is a menu, not a pill row: the
  sensor page has thirteen station types, which wrapped onto a second line and
  pushed the table down the screen. The dropdown names the active type, carries
  a count per option, and keeps a separate chip to clear it. Anchor such a menu
  to the edge its trigger sits on (`left-0 sm:left-auto sm:right-0`) — the
  header's actions are left-aligned on a phone and right-aligned from `sm`, so a
  fixed `right-0` runs the panel off the screen.
- Tables are for pointers: below `sm` every table renders the same rows as
  cards (`sm:hidden` list next to a `max-sm:hidden` table) — sensors, alerts,
  reports, the access lists and the threshold editor all do it. Touch targets
  stay at 40px or more. A table left to scroll sideways on a phone hides its
  own action column, which is where Tinjau/Selesaikan and Ubah/Hapus live.
- Blade elements take exactly one `style` attribute — a second one is silently
  dropped by the parser, which is how the panel once lost its positioning and
  landed on the left of the screen. Merge declarations into the existing one.
- Anything positioned from `--stage-*` or `--panel-w` carries `chrome-slide`, so
  folding a panel moves the layout on the same curve as the panel itself
  (disabled under `prefers-reduced-motion`).
- Folding the summary panel is a digital-twin affordance only (`$panelFoldable`
  in the layout): the other pages are a document in that column, so the handle is
  not rendered and `panelCollapsed` starts false there — otherwise a fold made on
  the stage would hide the panel on a page with no way to bring it back.
- The summary panel folds away on desktop through `panelCollapsed` (persisted in
  `localStorage`, class `app-frame--panel-collapsed`). The panel keeps its own
  `--panel-w-open`; only `--panel-w` — what the stage reserves — goes to zero, so
  the stage widens without squashing the panel mid-animation. Collapsed also
  means `inert`, so focus cannot land in it.
- The summary panel takes a `skipPrimary` flag; the dashboard sets it because
  the page already prints those four tiles across the top.
- A page that has nothing for the panel must not declare the section at all:
  the layout renders the panel only `@hasSection('panel')` and otherwise adds
  `app-frame--no-panel` (`--panel-w: 0`). An "empty" panel is still a
  full-height `pointer-events-auto` column parked over the right of the page —
  it swallowed every click in that strip, which is what made the buttons at the
  top right of Perawatan, Peringatan, Data Sensor and Pengguna & Akses look
  dead. Stubbing `<div></div>` into the section brings that back.
- Tailwind v4's preflight gives `<button>` `cursor: default`. `app.css` puts the
  pointer back on buttons, tabs, `[role=button]`, checkboxes and selects, and
  `not-allowed` on anything disabled — without it every control in the app reads
  as scenery.
- The left column is one element: `partials/sidebar` on top, `system-monitor`
  pinned to its bottom, both the width of `--rail-w`. `compact` lives on the
  `.app-frame` x-data and toggles `.app-frame--rail-compact`, which rewrites
  `--rail-w` — that is what keeps the rail, the stage inset and the monitor in
  agreement. Below 1440 the monitor renders as a strip of status dots instead of
  clipped rows.
- The frame is `w-full`, never `w-screen`: `100vw` includes the scrollbar, which
  used to push the summary panel ~15px off the right edge on any page tall
  enough to scroll.
- A menu opening over another glass panel needs `.glass--menu` (near-opaque);
  plain `.glass--panel` lets the numbers underneath read straight through it,
  and Chromium drops the backdrop blur inside the zoomed chrome. The header sits
  at `z-50` so the user menu is not painted under the summary panel.
- Never hard-code layout offsets in a view. The chrome metrics live as CSS
  variables on `.app-frame` (`--gap`, `--rail-w`, `--panel-w`, `--header-h`,
  `--stage-*`, `--ui-zoom`); `map-stage.js` measures the real rail/header/panel
  through `data-chrome="…"` attributes and a ResizeObserver. Elements that should
  grow on large monitors carry `chrome-scale`.
- Every page except the twin stage draws the base panorama as its backdrop
  (`page-shell.blade.php`, and the login screen), in the phase the sun is
  actually at, and the stage sphere is graded by the same numbers plus a night
  wash. Never swap `background-image` on a live layer: the decode shows up as a
  blink. Interpolate in weight space (`site.phaseWeights`), not by picking the
  nearest curve sample.
- `site.backdrop` is `site.scene` over a different set of stills — the panorama
  previews from `environment.stage.base.phases` — so skin lock, scrubbing and
  the cross-fade all keep working. It falls back to the orthographic stills
  (`scene.assets`) when the stage has no base station.
- The backdrop drifts with `.backdrop-pan`: an equirectangular picture wraps, so
  repeating it and translating by exactly one tile loops seamlessly. The tile is
  sized in `vh` (2:1, 120vh tall so it always covers) because the translation
  and the tile must be the same length — deriving the width from `auto` and
  translating in `vh` drifts apart and shows a seam. Animate the transform, not
  `background-position`: the layer is blurred, and repainting a blurred
  full-screen image every frame is not free. Below `sm` it holds still (the tile
  would be several screens wide) and `prefers-reduced-motion` stops it.
- `site.start()` takes a `live` flag (the layout passes `$hasPanel`). Pages
  without the summary panel read dashboard numbers only in the system monitor,
  so they poll `/api/dashboard` at a quarter of the rate instead of the full
  one. The environment poll stays at full rate everywhere — the clock, the
  scene and the backdrop phases hang off it.
- The stage clock has two modes in the `site` store: `realtime` follows the
  server clock, `custom` drives lighting from `/api/environment/curve` (a day of
  samples) so it can be scrubbed or played back faster. Never re-implement the
  solar maths in JS — extend `SolarClock` and the curve instead.
- Station search is rendered in the header (`partials/station-search`, twice: a
  field next to the clock and an icon for phones) but only on the digital twin
  (`$stationSearch` in `partials/topbar`). It steers the stage's camera, so it
  belongs to the page that has one — on the other pages the header was offering
  a search whose only outcome was leaving the page. It fires a `focus-station`
  window event the stage listens for; the `/` shortcut finds no field elsewhere
  and leaves the key alone.
- A pin picked from search is `highlighted`: it wears its caption even with the
  `Label` pill off, and keeps it until the reader touches the sphere or opens a
  station. Turning the camera to a dot without naming it leaves the reader to
  guess which one was meant.
- Marker captions are behind the `Label` pill on the bottom bar (`showLabels` in
  `twin-sphere.js`, remembered in `localStorage` under `twin.labels`); the CSS
  class `sphere--quiet` hides them, hover still reveals one.
- `map-stage.js` is the older orthographic-render stage. It is kept registered
  but nothing mounts it since the stage went 360; the render itself still ships
  as the page background.
- Breakpoints: the rail loses its labels below 1440, the summary panel becomes a
  slide-over below 1280, and below 640 the rail moves to the bottom of the screen
  (`measure()` detects that and turns it into a bottom inset). On portrait or
  narrow viewports the stage fits the photo region instead of covering
  (`coverStage`), so the whole dam stays visible.
- New CSS features need a fallback: `zoom`, container queries, `backdrop-filter`
  and the SVG glass filter are all behind `@supports` so Firefox/Safari degrade
  instead of breaking. Keep that pattern.
- The dam render is full-bleed (cover fit). Only its middle holds real photo
  pixels; the margins are mirrored terrain sized to the chrome
  (`extend_canvas()` in `tools/build_map_assets.py`), so marker percentages
  (`map_x`, `map_y`) refer to that inner region and go through `dam.map.content`
  — see `contentFraction()` in `resources/js/components/map-stage.js`. Changing
  the chrome proportions means updating `CHROME`, rebuilding, and copying the
  printed `width`/`height`/`content` into `config/dam.php`.

## Icons

- One file, `resources/views/components/icon.blade.php`: a name-to-path map
  rendered into a 24x24 stroked SVG (`stroke-width` 1.7, round caps). Adding a
  glyph means adding one line; nothing else changes.
- Pick the name for the meaning, not the picture — a bell standing in for a
  conversation, or a rotated back-arrow standing in for a chevron, is how the
  set drifts. `x-icon` falls back to `sensor` when a name is unknown, so a typo
  shows up as the wrong glyph rather than a blank space.

## Access control

- Abilities are declared in `config/access.php` and answered by one
  `Gate::before` in `AppServiceProvider`, so every check is `can('code')` /
  `@can` — there is no second mechanism. Returning `null` rather than `false`
  from that hook leaves anything outside the catalogue to Laravel's own gates.
- `users.role` holds a role *slug*; `Role` is joined on it. It cannot be an
  Eloquent relation named `role` because the attribute of that name shadows it,
  hence `User::accessRole()` with its own per-slug cache. A request loads the
  account fresh, so editing a role takes effect on the next request — a test
  that changes permissions mid-test must re-read the user (`->fresh()`).
- The role also carries `desk_side` (`operator` or `cs`). Nothing may go back to
  reading `$user->role === 'operator'` to pick a side: a site can name its roles
  anything, and two roles can sit on the same side. `User::deskSide()` is the
  answer, and it falls back to the old rule when the roles table is empty so a
  half-seeded install still renders.
- The administrator role always carries the whole catalogue — the controller
  forces it on save. It is the role that hands out access; narrowing it is how
  an install locks itself out. The same reasoning guards the last active
  account with `users.manage`.
- Gate the route *and* the control that calls it. A visible button that answers
  403 is worse than no button: the rail filters its own items, and each page
  hides the affordances its ability does not cover.
- The roles screen is a table of roles, not a wall of checkboxes: what a role
  may do is only in its dialog. Ten roles times ten abilities is not a list
  anyone reads, and the table has to stay scannable.
- Never show a disabled checkbox on these panels — greyed on dark reads as
  unticked. The administrator's dialog hides the ability grid altogether and
  says so instead; its abilities are set by the controller anyway.
- `UserFactory` sets `role` and `is_active` because the database defaults never
  reach the in-memory model, and a factory user with no role resolves to no
  abilities — which shows up as unexplained 403s in tests.
- Adding, editing and deleting on the access screen happen in dialogs
  (`.modal-scrim` / `.modal-card`), and each one must be wrapped in
  `<template x-teleport="body">`: the page content sits in a transformed,
  `z-20` stacking context, so a `position: fixed` scrim declared inside it is
  trapped under the header no matter how high its `z-index` climbs.
- Neither the scrim nor the dialog card may carry `backdrop-filter`: creating
  that layer over the animating panorama backdrop, and rebuilding it while the
  card scales in, is what flashed on open. The scrim dims with paint and the
  card is opaque, which looks the same and arrives on the first frame. Same
  rule as the pin captions — nothing that moves may be backdrop-filtered.
- A teleported node keeps the component's scope but not its `$refs`, so the
  dialog focuses the first field by querying the visible scrim — and it does so
  a frame later, because `focus()` does nothing while `x-transition` still has
  the card at `display: none`.
- `close()` drops the dialog's `target` on a timer, not immediately: the card
  is still leaving, and rewriting it mid-animation flips every "Ubah …" title
  to the "new" wording and grows the administrator's hidden ability grid back —
  which is the blink on the way out. Opening again cancels that timer.
- The writes themselves stay ordinary form posts: validation, CSRF and the
  redirect belong to the server. A save that fails carries a hidden `intent`
  (and `target_id`), which is how the page knows to reopen that dialog with
  `old()` in it instead of dropping what was typed.

## Maintenance desk

- A maintenance job (`maintenance_tasks`) carries its own conversation
  (`maintenance_messages`). `author_role` is `operator` (control room) or `cs`
  (service desk) — the pair is what the unread badges count against, and the
  side comes from the signed-in account's role (`User::deskSide()`), so nobody
  picks their own side.
- A bubble is aligned by *author*, not by side: `mine()` compares `user_id`
  with the reader's own (falling back to the side when a row has no author).
  Two operators share a side, and a colleague's line hanging on your own margin
  reads as something you wrote. The side still picks the tint and the badge, so
  the thread stays legible about who answers from where.
- The thread is built by `thread` in the desk component, not rendered straight
  from `messages`: it inserts a day heading when the date turns, groups
  consecutive lines by the same author (one avatar and name per burst, the
  clock under its last line) and marks where the unread run began. That
  boundary is captured in `open()` *before* the read call, or it would vanish
  in the same breath as the badge.
- The two panes own their height (`lg:h-[calc(100dvh-var(--stage-top)-176px)]`)
  and scroll inside it. A chat that grows the page is a chat whose composer
  walks off the bottom of the screen.
- Both panes carry `min-w-0` as well as `min-h-0`. A flex child does not shrink
  below its content, so without it the ticket buttons grew to fit the longest
  preview, `truncate` had nothing to truncate against, and the list hung out of
  the panel (623px of list in a 345px column).
- Asking for work is a dialog (`.modal-scrim`, teleported to `<body>`), not an
  inline panel: the form used to push the whole desk down the page. It closes
  itself on success and opens the ticket it just created.
- Every count of unread is taken from the reader's own side
  (`MonitoringService::otherSide()`); the boot payload used to hard-code
  `operator`, which showed the service desk the control room's tally.
- A request from the control room creates the job with `source = permintaan` and
  posts the first message in the same call; scheduled work stays `source = jadwal`.
- `last_message_at` orders the list. Update it whenever a message is added, or
  the desk sorts by the wrong column.
- Reading a thread only clears what the *other* side wrote; a page can therefore
  refresh its own badge without erasing what it never saw.

## Assets

- Dam backgrounds and panoramas are generated by the Python scripts in `tools/`
  from `D:\BE Software\Panoramic 360 fix`. Re-run them instead of editing files
  under `public/assets/` by hand.
- Four time-of-day stills are required: `map-night`, `map-dawn`, `map-day`,
  `map-dusk`. `SolarClock::scene()` cross-fades between two of them. Their pixel
  size must match `dam.map.width` / `dam.map.height` in `config/dam.php`.
- Sources are low-resolution, so they are upscaled with Real-ESRGAN
  (`tools/upscale.py`, weights in `tools/models/`, not committed). Panoramas are
  wrap-padded before super-resolution so the 360 seam stays continuous.
- `tools/build_hd_masters.py` bakes that step into HD masters under
  `D:\BE Software\Panoramic 360 HD` (originals untouched). When those exist both
  build scripts read them and skip the GPU work — prefer regenerating masters
  with `--force` over editing the delivery webp files.
- The base panorama has four textures built by `tools/build_panorama_phases.py`
  from `Transitions/Base Dam` (one render per phase, same viewpoint). The stage
  picks the one matching the dominant solar phase and cross-fades between them,
  so `loadHd()` must resolve the phase asset too — pointing it at the station's
  own `panorama` puts the plain daylight sphere back on screen.
- Delivery spheres are 4096x2048 at WebP q82, previews 2048x1024 at q72. The
  sources are 1774px wide, so everything past ~4096 is upscaler invention: the
  old 5120/6144 q90 files were twice the bytes for detail that was never in the
  photograph (44 MB of textures became 21 MB with no visible loss).
- Panoramas ship in three tiers (`<code>.webp`, `preview/` at 2048px, `thumb/`).
  The preview goes up first and the HD is warmed through
  `textureLoader.preloadPanorama()` in the same breath, so the sharpening waits
  on the animation, not on the network. Never gate `loadHd()` behind the fade's
  promise — a stalled transition would leave the sphere blurred for good.

## Commands

```bash
php artisan test                  # feature tests (API, ingest, auth)
npm run build                     # or npm run dev for HMR
php artisan telemetry:simulate    # extend demo readings to now
```

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
- Icon-only controls carry `aria-label` as well as `title`; a tooltip is not a
  name for touch or a screen reader. Toggles also carry `aria-pressed`.
- The bottom bar pills filter the pins (`pinFilter` in `twin-sphere.js`, water
  types listed in `waterTypes`). They used to dispatch an event nobody listened
  to — controls that look live must do something.
- Tables are for pointers: below `sm` the sensor list renders the same rows as
  cards (`sm:hidden` list next to a `max-sm:hidden` table). Touch targets stay
  at 40px or more.
- Blade elements take exactly one `style` attribute — a second one is silently
  dropped by the parser, which is how the panel once lost its positioning and
  landed on the left of the screen. Merge declarations into the existing one.
- Anything positioned from `--stage-*` or `--panel-w` carries `chrome-slide`, so
  folding a panel moves the layout on the same curve as the panel itself
  (disabled under `prefers-reduced-motion`).
- The summary panel folds away on desktop through `panelCollapsed` (persisted in
  `localStorage`, class `app-frame--panel-collapsed`). The panel keeps its own
  `--panel-w-open`; only `--panel-w` — what the stage reserves — goes to zero, so
  the stage widens without squashing the panel mid-animation. Collapsed also
  means `inert`, so focus cannot land in it.
- The summary panel takes a `skipPrimary` flag; the dashboard sets it because
  the page already prints those four tiles across the top.
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
- The four time-of-day stills are the background of every page except the twin
  stage (`page-shell.blade.php`), and the stage sphere is graded by the same
  numbers plus a night wash. Never swap `background-image` on a live layer: the
  decode shows up as a blink. Interpolate in weight space (`site.phaseWeights`),
  not by picking the nearest curve sample.
- The stage clock has two modes in the `site` store: `realtime` follows the
  server clock, `custom` drives lighting from `/api/environment/curve` (a day of
  samples) so it can be scrubbed or played back faster. Never re-implement the
  solar maths in JS — extend `SolarClock` and the curve instead.
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
- Panoramas ship in three tiers (`<code>.webp`, `preview/`, `thumb/`); the viewer
  shows the preview first and swaps in the HD texture with
  `transition: false, showLoader: false`.

## Commands

```bash
php artisan test                  # feature tests (API, ingest, auth)
npm run build                     # or npm run dev for HMR
php artisan telemetry:simulate    # extend demo readings to now
```

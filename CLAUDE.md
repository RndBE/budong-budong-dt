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

## Environment

- The app runs on MySQL (`DB_CONNECTION=mysql`, schema `budong_budong_dt`);
  sessions, cache and the queue are database-backed, so a schema swap logs
  everyone out. Tests are unaffected — `phpunit.xml` pins them to an in-memory
  SQLite.
- On a `sqlite` connection Laravel reads `DB_DATABASE` as a *file name*, not a
  schema: leaving `DB_DATABASE=budong_budong_dt` there quietly creates a
  `budong_budong_dt` file in the project root and the app runs off that. Both
  that file and `database/database.sqlite` are gitignored.

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
- A reading is always a number, so state parameters (status sensor, kondisi
  pintu, status sirene) store the number and the metric carries the words in
  its `states` map — `SensorMetric::stateLabel()` looks one up. That keeps one
  storage shape: the state still charts as a step and still has thresholds.
  Do not add a text column to `sensor_readings` for them.
- Every parameter the product sheet lists has a metric, including the raw ones
  (`raw_reading`, `frequency_raw`) and the survey figures behind ADR
  displacement (`distance`, `angle_h`, `angle_v`, `coord_e/n/h`). Adding a
  metric to `StationSeeder` also means adding its case to `ReadingSimulator`,
  or the parameter exists with an empty chart.

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
- Nothing of a station goes up until its picture has landed:
  `syncPins()` draws hotspots only while `stationView && !pointing && !flying`.
  `viewer.open` is true from the moment the pin is picked, so without the
  `flying` guard the arrival drew the station's petak and prisms over the base
  dam — dashed plots lying across the reservoir for the length of the
  cross-fade. The pins fade out under `sphere--departing`; the hotspots have
  no such cover, because they are not supposed to exist yet.
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
  not swing the opening framing. Stations open the same way, on their own
  `panorama_bearing` — the direction the reader is meant to be facing at that
  instrument, which is a survey figure and not a framing preference; a station
  without one falls back to its raw `panorama_yaw`. The reset control uses the
  same helper.
- A panorama is facing its opening bearing the moment it appears, and nothing
  turns on screen to get there: `setPanorama` is given a `position` *with*
  `transition.rotation: false`. That pair is not a contradiction — the viewer
  pre-rotates the incoming sphere so the requested framing already sits where
  the camera points, then swaps camera and sphere together once the fade ends.
  `rotation: true` would animate the camera across both pictures at once,
  which is the blink; turning after the fade instead is a move the reader can
  see and did not ask for. Only `loadHd()` may omit the position — it is the
  same picture sharpening, so it must keep whatever framing the reader has.
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
- A station pin names one parameter at a time and walks through the rest
  (`readings` in the marker payload, `CAPTION_EVERY`): a pin that shows one
  figure and hides nine behind a click is a pin the reader has to leave the
  stage to get past. The cycle rewrites only the two lines of text, found
  through `[data-station]` in our own markup — re-rendering the marker would
  restart the dot's breathing animation, and fifteen of those restarting in
  step is a twitch across the whole stage. It stops inside a station,
  mid-flight, with the `Label` pill off, and on a hidden tab. The figure wears
  its own parameter's status; the dot keeps the station's, which is the worst
  of them and the reason to look at all.
- Pin captions are **not** decluttered, and that is a decision rather than an
  omission: every caption stays where its pin is, and two of them overlapping
  is accepted. Hiding the loser was tried first (pins seemed to vanish the
  moment the `Label` pill went on), then shrinking it to the name alone, then
  moving it aside — and each of those cost more than the overlap did. Moving
  in particular has to fight three separate sources of flicker: the offset has
  to be subtracted from the measured box rather than cleared off the element,
  last frame's spot has to be tried first or two captions swap sides for ever,
  and `setMarkers` hands back new elements every poll with the offsets gone.
  Before re-adding any of it, be sure the overlap is actually worse.
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
- Placement is one control for both views: the base panorama moves station
  pins, a station panorama moves that station's own hotspots (posting to
  `/api/hotspots/{id}/position`). `bindDragging()` picks a marker up by
  `[data-station]` or `[data-hotspot]` depending on which view is on screen,
  and the drop writes the new angles into the payload as well as the record —
  the next `syncPins()` rebuilds every marker from `station.hotspots`, so a
  position left only on the server snaps back. Both wrap the yaw into
  -180..180, the way the columns are read back.
- A line of monitoring plots (`type = 'plot'`) is one record for five petak,
  one per stake. Its `meta` carries `side`, `code`, `stakes`, the spacing
  between stakes (`stake_gap`), the size of one petak (`cell_yaw`,
  `cell_pitch`), the direction the line runs (`line`) and how much each step
  crowds (`foreshorten`); every petak, every stake and the caption are
  *derived* from the record's centre, and the stake names come from the code
  (`PanoramaHotspot::stakeCodes()`). Sixty records to drag one at a time is
  not a placement tool.
- `line` is the direction of the stake sequence in the picture (0 = right,
  90 = down), because a survey line runs along the dam, not across the frame:
  three lines step away from the crest and five stakes march along the axis in
  each. `foreshorten` shortens every step, which is what makes a receding line
  read as lying on the slope instead of painted flat over it. `StationSeeder`
  still has to keep a nominal petak inside a nominal step — `|cos(line)| *
  cell_yaw + |sin(line)| * cell_pitch` below `stake_gap`, which is what the
  test asserts — but what is *drawn* is derived from the placement, not from
  those figures.
- A petak is turned onto the line it belongs to (`stakeAim()`), because a plot
  is a patch of the dam and the dam crosses the picture at an angle: an
  upright rectangle over an oblique face reads as pasted onto the photograph
  rather than lying on it. The angle comes from the prisms either side of the
  stake, not from `meta.line` — once a line has been placed by hand it no
  longer runs the way the record laid it out, and the petak have to follow the
  placement. `cell_pitch` is then the petak's extent *along* the line and
  `cell_yaw` its extent up and down the slope, which is what those two come to
  once the line runs up and down the frame — so a line nobody has turned looks
  exactly as it did.
- `meta.places` is the one thing about a line that is not derived: an offset
  per stake, written by dragging that stake, so a line can be placed as a
  piece and then corrected prism by prism. Offsets, not angles — moving the
  line afterwards has to carry every correction with it. A caption drag posts
  to `/api/hotspots/{id}/position`, a stake drag to
  `/api/hotspots/{id}/stakes/{n}`, and `nudgedPlot()` measures the offset
  against where that stake *would* have stood with no nudge at all.
- Each petak is a projected `polygon` marker: points in spherical
  coordinates, so it lies on the slope. Its stake is the opposite — an HTML
  marker of a fixed pixel size, standing in the middle of it. Drawn to scale a
  patok is a metre of concrete at 130 m, a third of a degree, a speck nobody
  can find at the widest zoom; it is a sign, so it keeps a legible size while
  only the ground it stands on scales. The petak is `pointer-events: none`, so
  clicks belong to the stake in it.
- The stake marker is a 16px symbol in a 26px box, and the extra ring is hit
  area: a 16px target on a photograph is a target people miss. It carries
  `role="button"` and its code plus its reading as `aria-label`, so the marker
  has a name for a screen reader as well as a tooltip.
- What is *printed* under a stake is its linear displacement in mm, coloured
  by the status that figure earns — the code stays a tooltip. That is also why
  a stake wears a status colour when its petak does not: the petak is ground,
  the figure is a reading.
- Those figures appear with the zoom that makes room for them
  (`sphere--near`, and always under the pointer). A line running away from the
  camera crowds its far stakes to ten pixels apart at the widest zoom, and no
  40px plate fits between them; the readout that never competes for room is
  the one in the panel.
- `MonitoringService::stakes()` is where a per-prism figure comes from. The
  instrument measures the dam body (`displacement_h`, `displacement_v`), not
  one stake; a plot's prisms are where that movement is *distributed*, so each
  stake takes a fixed share derived from `crc32` of its own code. The share
  must be a function of the code and nothing else — seed it from time or
  `rand()` and the deformation field shimmers on every poll.
- A prism reports how far it has moved **since it was set**, not how far it
  moved this cycle, so each carries years of accumulated creep on top of its
  share of the current reading — and they do not carry the same amount. The
  creep is cubed off the code hash, which gives the shape a real face has: many
  prisms well inside their band, a handful past the warning, one or two past
  the alert. Without it every prism read two millimetres and the whole field
  was green, which is a picture that never says anything and thirty prisms
  nobody needs. The components are rescaled to the grown figure so the panel's
  horizontal and vertical still add up to the linear it prints, and a test
  asserts the field spans more than one band.
- `aspect` in a plot's `meta` is an angle **in the picture** (0 = right,
  90 = down), not a surveyed azimuth, and it is what the arrow is rotated to.
  The panel labels it "Arah gambar" for that reason. Deriving a real bearing
  would need a camera model the renders do not carry — do not print one. In
  the ADR-02 panorama the reservoir is on the left, so the body is pushed to
  the right and every plot's aspect is a few degrees below the horizontal: a
  little downstream travel plus a little settlement. Per-stake deviation is
  ±10 degrees, wide enough that prisms do not creep in lockstep and narrow
  enough that the field still reads as one direction.
- The arrows are behind a toggle (`showVectors` / `sphere--vectors`) and live
  inside the stake marker rather than as markers of their own: thirty extra
  markers for a mode nobody has asked for is thirty transforms a frame. The
  arrow's length is the size of the movement, so the scale it was drawn at is
  printed on screen beside it — a length with no scale is a picture that
  cannot be read. That scale is `VECTOR_SCALE` in `panorama.js` and the
  caption prints it through `twinSphere.vectorScale`, so the two cannot drift
  apart. It is deliberately small: a dam creeps in millimetres, and an arrow
  that turns two of them into forty pixels of pointer tells the reader
  something the instrument did not.
- The millimetre figures have a toggle of their own (`showFigures`, remembered
  under `twin.figures`), which forces `sphere--near`. Two separate controls,
  because they answer separate questions — which way, and how far — and the
  far end of a line overlaps when the figures are held open at a wide zoom.
  That is the reader's call to make, so it is a button, not a rule.
- Placement writes a record, so it may not be triggered by a click:
  `DRAG_SLOP` (4px of pointer travel) separates a drag from a tap. Without it
  a stray click in placement mode silently rewrites a marker's angles — which
  is a survey figure changing because somebody pointed at it.
- Seeded angles for a plot or an instrument are an estimate off the picture,
  because the renders are not surveyed. Correcting them from the stage is the
  only honest way, which is what the placement control is for.
- Placement lives in the database, so **pushing code does not carry it**:
  a new environment seeds the catalogue's estimates and an afternoon of placing
  prisms on the real bays is gone. `php artisan placements:export` writes the
  current pins, hotspot angles and per-prism nudges to
  `database/seeders/data/placements.php`; commit that and `StationSeeder` reads
  it **when it creates a row**. Only on create, the same rule a dragged marker
  has always had — an install that already has placements keeps them. Re-run
  the command after a placing session, and a test asserts the round trip
  against the file itself.
- Those two halves are what a deploy actually needs, at once: the target's own
  pins stay where somebody dragged them, and a marker that is *new* there comes
  up on the angles exported from the machine it was placed on. Never run
  `placements:export` on the target before deploying to it — that overwrites
  the file with what the target already has, which is precisely the placement
  the push was carrying. Export where the placing was done, commit, push.
  `test_a_deploy_keeps_placed_pins_and_brings_new_plots_with_it` is that run.
- A rename is the exception, because placement is keyed by station `code` and
  hotspot `label`: renaming either retires the old row and creates a new one,
  so the new one takes the *file's* angles, not the target's. Anything placed
  on the target under the old name has to be placed again.
- The catalogue is authoritative for *stations* too: a station whose code
  `StationSeeder` no longer lists is deleted, and its readings, metrics and
  hotspots cascade with it while alerts and maintenance jobs keep their history
  on a null station. Nothing else ever deletes one, so without that a renamed
  or retired point stayed on the stage for ever. The same now goes for a
  metric the catalogue drops.
- Every station that has any instrument at all also carries the three channels
  the logger reports about *itself* — `logger_battery`, `logger_temperature`,
  `logger_humidity` (`StationSeeder::LOGGER_METRICS`, appended after the
  catalogue). Named `logger_*` because a weather station already reports the
  air's temperature and humidity and those are a different thing. A flat
  battery or a damp enclosure is why a station stops reporting, and it shows up
  here days before it shows up anywhere else. The overview panorama gets none:
  it has no box on a pole, and three parameters would put it in the analytics
  station list with nothing to draw.
- `StationSeeder` upserts hotspots on `(station, label)`, writes `yaw`/`pitch`
  only when the row is created, and carries an existing `meta.places` over the
  catalogue's `meta`, so a re-seed refreshes the copy without throwing away
  where somebody placed a line or nudged one of its prisms. It therefore has to prune by
  what it touched (`keptHotspots`) instead of deleting the station's hotspots
  first — and the link pass has to go through the same upsert, or every run
  would add another copy of every jump.
- `x-show` hides an element by writing `display` into its `style` attribute, so
  an element may not carry both `x-show` and a `:style` binding: the binding
  rewrites the whole attribute and the element comes back. Bind a class
  instead (the placement hint's `bottom-…` is why). A *static* `style`
  attribute is fine — Alpine only appends to that.
- The station overlay has no strip of neighbouring panoramas. Six type icons
  said nothing about where those panoramas are; the way across is a `link`
  hotspot standing in the direction the reader would walk, or the pins on the
  base view.
- `<template x-for>` does not work inside `<svg>`. Build SVG fragments as a
  string in the store and inject with `x-html` (see `site.healthDonut`).
- Heavy libraries (photo-sphere-viewer with its three.js, echarts) must stay
  behind dynamic imports so the entry bundle stays small.
- Analytics is one screen with two views, `grafik` and `analisa`: a chart per
  parameter (all of a station's, or the headline parameter of every station
  when `scope = 'semua'`), and the parameters the reader ticks drawn into a
  single chart. A card in the grid is a shortcut into `analisa` — never a
  second menu entry, which would duplicate the station and range controls and
  make the reader choose before they can look. Station, range and mode live in
  the URL and nowhere else — `?stasiun`, `?rentang`, `?tampilan`,
  `?parameter` (an older `detail`/`kisi` still resolves), read on boot and
  written back by every control. They used to persist in `localStorage`, which
  cost the screen both ends of its job: the Grafik button on Data Sensor named
  a station and landed the reader on whatever they last had open, and the menu
  entry could never be a neutral way in. A plain visit opens on `semua` — every
  station's headline parameter, the one view that answers a question nobody
  has asked yet.
- The station list is only stations that *have* parameters. The overview
  panorama has none, and offering it left the reader on an empty screen with
  no way to tell the station from a broken page — and because the choice is
  remembered, every later visit opened blank too. The grid still says so when
  a scope turns out to have nothing to draw.
- One range governs the whole grid. Per-card ranges would put charts side by
  side that cannot be compared, which is the only reason to place them there.
- Grid cards draw when they scroll into view (one `IntersectionObserver`,
  `rootMargin` 160px): sixteen charts built at once is a frozen second for rows
  nobody has reached. The observer only reports a *change*, so returning from
  `analisa` — where every card was `display: none` — calls `drawInView()`,
  which measures rather than waiting for a scroll that may never come.
- The chart keeps at least one series, and the **last chip says so** rather than
  refusing in silence: tapping the only picked parameter to swap it did nothing
  at all, which reads as a chart that never changes. It carries no remove
  button and its title explains.
- `loadAnalysis()` has a `catch`. It did not, so a failed series request left
  the previous chart on screen — correct-looking, stale, and unannounced. A
  picture that quietly refuses to update is worse than no picture.
- `analisa` combines units through a second y-axis, and stops at two. Each axis
  carries its unit as its name; a third scale on one chart is a picture nobody
  can read, so those chips are rendered disabled with the reason in the title.
  Thresholds are drawn only when a single parameter is picked — every set at
  once turns the chart into a ladder. Statistics describe the first parameter
  picked and say so.
- Arriving and changing are two different movements. A chart is rebuilt with
  `notMerge` whenever the picked parameters change, so without an update pair
  (`animationDurationUpdate`, `animationEasingUpdate`) every swap replayed the
  full entrance and the picture snapped instead of moving. The line also draws
  itself left to right (`animationDelay` per point, capped) and a second series
  follows the first by a beat, which is what makes two of them read as two. The
  canvas dims while its numbers are in flight, so a swap is one movement rather
  than a stale picture replaced by a new one — and all of it is off under
  `prefers-reduced-motion`, like everything else here that moves.
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
- `.glass` carries one drop shadow, wide and soft, and it is named
  (`--glass-lift`) so it can be taken away in one place. A tight `0 2px 10px`
  under it reads as a thin black line drawn down the edge of a full-height
  column rather than as a shadow — a lift belongs to a card, not to a wall.
- Inside the rail and the summary panel the lift is off entirely
  (`.rail-column .glass`, `[data-chrome="panel"] .glass`). Plates stacked in a
  column cast on each other: the card above drops its shadow into the gap and
  onto the head of the card below, which reads as dirt in the seam rather than
  as depth, because a shadow falling on the thing beside it says nothing about
  height. The hairline is the whole edge there.
- The compass sits flush with the top of the stage, on the line the rail and
  the summary panel start on (`--header-h`). The three are the same row of
  chrome and have to read as one; eight pixels of inset was enough to make the
  stage look misaligned with the menu beside it.
- The panel's fold control is the first button in the stage's own right-edge
  stack, not a chip parked beside it. Floating at `top: 50%` it landed on
  whichever control happened to be at the top of that column — the column grows
  and shrinks with the reader's abilities and the station on screen, so there
  is no offset that clears it. A control that has to dodge another one is in
  the wrong place. It reaches `togglePanel()` through the frame's own
  `x-data`, which the stage sits inside.
- The summary panel folds away on desktop through `panelCollapsed` (persisted in
  `localStorage`, class `app-frame--panel-collapsed`). The panel keeps its own
  `--panel-w-open`; only `--panel-w` — what the stage reserves — goes to zero, so
  the stage widens without squashing the panel mid-animation. Collapsed also
  means `inert`, so focus cannot land in it.
- Two surfaces are arranged, together and by one control: the page's own grid
  (`board`) and the summary column down the right (`panel`). They are one
  screen to the reader, so ordering half of it would be half a feature — but
  they are stored under separate keys, because the panel follows the reader
  onto every other page while the board does not. `DashboardLayout::SURFACES`
  names both.
- The arranger is an Alpine **store**, not a component, for exactly that
  reason: the two surfaces live in different Blade sections and one control has
  to reach both.
- `boot()` seeds state and touches nothing on the page. The server already
  rendered the saved arrangement, so there is nothing to move — and moving it
  anyway re-parented every card while Alpine was still initialising, which
  tears down and rebuilds the components inside them. The trend chart came back
  with its `$refs` pointing at a dead subtree, and `echarts.init(undefined)`
  threw *Cannot read properties of undefined (reading 'getAttribute')*. Only
  `cancel()` moves nodes, and then only ones that are not already in place: an
  unconditional `appendChild` is a re-parent, and a re-parent costs the
  components inside a card their lives.
- `seriesChart()` and `sparkline()` refuse an element that is not there, by
  name. `echarts.init(undefined)` throws a message that identifies nothing,
  which is a long way from the caller that lost its element.
- The dashboard is arranged once, for everybody (`App\Support\DashboardLayout`,
  stored in `settings` under `dashboard_layout`, written behind
  `dashboard.arrange`). Not per account: it is the control room's own screen
  and often the thing on the wall, so two operators looking at it should be
  looking at the same board.
- Each card is a partial under `partials/dashboard` named by its key; adding
  one means adding it to `DashboardLayout::CARDS` and dropping the file in
  beside the others. The grid is six columns so the original board is
  reproducible — a third, a half, two thirds, or the whole row — and below
  `lg` the spans are ignored and everything stacks, because a layout arranged
  on a control-room screen does not survive a phone.
- `current()` is always complete and always valid: a card added to the
  catalogue after somebody saved appears at the end rather than vanishing, and
  a saved key that no longer exists is dropped. `save()` trusts nothing from
  the browser — unknown keys, duplicates and impossible widths go, and a fixed
  card cannot be narrowed or hidden however the request is shaped. There is no
  `reset()`: saving an empty list is one.
- A card can also be pointed at *what it shows*: `DashboardLayout::choices()`
  builds the offer from the instrumentation catalogue at request time, so a
  card can only ever name a parameter, station or type the site actually has.
  Headline tiles pick their parameters, the trend card its station, the station
  list a type, the two list cards a row count. An option outside the offer is
  **dropped, not corrected** — an empty option means "as designed", and a board
  falling back to its default is easier to explain than one quietly showing the
  wrong station.
- Choosing that content is a **dialog**, not controls wedged into the card.
  The headline row offers a hundred and forty-six parameters, and a
  `<select multiple>` that deep is a list nobody reads and a Ctrl-click
  nobody discovers. The picker searches over the whole label, groups by
  station, shows what is picked as removable chips in the order they were
  picked — the tiles come out in that order — and disables the rest once the
  maximum is reached rather than silently ignoring the next click.
- Saving reloads the page. The order and the widths would have survived without
  it, but what a card *shows* is server rendered — a different station on the
  chart or a different set of tiles is a different page, not a rearranged one,
  and a board that half-updated would be worse than one that took a second.
- The arranger reads the arrangement back out of the **DOM** rather than
  keeping a second copy in component state — order, width and visibility are
  all in the document, and a duplicate is how the two drift apart. The one
  exception is the per-card options, which are nowhere in the DOM and so are
  the one thing the component has to hold. Every move
  dragging can make is also on a pair of arrow buttons: a control that answers
  only to a pointer is one a keyboard cannot reach, and this one writes for the
  whole control room.
- No figure appears twice on one screen. `recentReadings()` drops anything
  already on the headline tiles — the two lists were configured independently
  and overlapped on all four, so a quarter of the board was the same numbers
  printed large and again in a list beside them. The board's alert card reads
  `alert_history` (newest, settled or not) while the summary column keeps
  `alerts` (standing only); before that both drew the same array and one of
  them said nothing the other had not.
- The station list is ordered by urgency, not by name, and the stations with
  nothing to report collapse into one line (`site.urgentMarkers()`). Seventeen
  stations that are all fine is one fact, not seventeen rows to scroll past —
  and alphabetical order put ADR-01 on top because of its first letter.
- The health ring prints a **count**, not a score. The percentage was a rounded
  fraction of parameters with the rounding drift absorbed into the largest
  bucket: two digits of precision for something that coarse. "2 dari 146" is
  the honest shape of it.
- The trend card prints how far the reading is from its next threshold. The
  chart already draws those lines, but on a reservoir sitting a metre and a
  half below its limit they are off the top of the picture — the gap in words
  is the part the shape cannot say.
- `DashboardLayout::PRESETS` holds arrangements somebody has already thought
  about. An arranger is worth as much as the arrangements people think of, and
  "default, or build your own" leaves most readers on the default for ever.
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
- Charts opt out of `chrome-scale` (`[data-chart] { zoom: calc(1 / var(--ui-zoom)) }`).
  ECharts maps the pointer with `offsetX`, and Chromium reports that as the
  *visual* offset while the canvas is sized from `clientWidth` — so under
  `zoom: 1.1` the crosshair drifts a tenth of the way it has travelled from
  the chart's left edge. Measured: the pointer read 330 of a 400-wide box
  where it should have read 300. Cancelling the scale costs nothing, because
  Chromium resolves a percentage against the parent in the child's own zoom
  space — `h-full` comes out the same size on screen, and net zoom 1 makes
  `clientWidth` and the bounding rect agree.
- `chrome-scale` is `zoom`, so every length written on that element is
  multiplied by it. Centre a floating plate by *spanning* the stage
  (`left: var(--stage-left); right: var(--stage-right)`) and letting flex do
  it — never by computing the midpoint into `left`, which on a screen scaled
  to 1.09 landed the station metric strip some ninety pixels right of centre.
  A percentage offset (`left-1/2`) inside a scaled parent is safe, because the
  parent is scaled with it; an absolute one is not. Position on an unzoomed
  parent and scale only the plate.
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
  and leaves the key alone — it checks `offsetParent`, not just existence,
  because `x-show` leaves a hidden field in the document.
- The search is hidden while a station panorama is open (`!$store.viewer.open`).
  Inside a station there are no pins to steer to, so the only thing the field
  could do is take the reader out of the picture they just opened.
- A pin picked from search is `highlighted`: it wears its caption even with the
  `Label` pill off, and keeps it until the reader touches the sphere or opens a
  station. Turning the camera to a dot without naming it leaves the reader to
  guess which one was meant.
- A hotspot chip is a flat plate, not `.glass`, for the reason the pin captions
  are: it travels with the sphere. A row's caption is the compact variant and
  sits above the petak, clear of every stake it would otherwise cover, and it
  is the drag handle for the whole row. `sphere--quiet` hides those captions too,
  so the `Label` pill declutters a station view as well (it is set from the
  base view, which is where the pill lives).
- A petak and the prism standing in it both wear that prism's status: green
  within its band, amber, orange, then red as it crosses each threshold. Never
  `offline` — that is a state a logger can be in, and a prism is a piece of
  glass on a stake; it is the total station that goes off the air. A prism with
  nothing measured yet reports a **null** status and is drawn plain (`UNREAD`),
  which says "not read" rather than "read and fine". Any `?? 'normal'` on a
  stake status puts the lie straight back. The
  ground is painted faintly (`fillOpacity` 0.16) with the outline at full
  strength — ground that shouts drowns the figure standing on it. Both keep the
  dark rim, which is what does the work: it is what a thin light shape was
  missing, and it is what lets either of them carry a colour without losing
  itself in rip-rap, grass or water. A plain white diamond disappeared into the
  concrete it stood on.
- The colour is written as marker `svgStyle`, from `statusColor()`, so the one
  palette answers for it. `.psv-cell` therefore declares no `fill` or `stroke`
  at all: a CSS declaration beats a presentation attribute, and it would take
  the status straight back off. The gate bays set theirs in CSS precisely
  because they are *not* status-coloured.
- `plotGrid()` builds the whole station at once, because none of what a petak
  needs is a property of one line. Each edge is measured in its own direction
  — along the line from the prism beside it, down the slope from the line
  below — and takes `PETAK_FILL` of it: the slope belongs to one prism or the
  next, so what is left between two petak is only the sliver that keeps two
  dashed outlines apart. One measurement for both edges sized the whole petak
  off whichever direction happened to be tighter, which drew slivers where the
  lines are far apart and the stakes are not.
- What finally limits a petak is the nearest prism *anywhere*, often on
  another line: three lines step down one slope, and hand placement leaves
  stakes from different lines a degree apart. `petakRoom()` is the
  separating-axis test solved for the scale rather than answered yes or no,
  and it must be a **pair** test — sizing each petak against a neighbour as if
  the neighbour were the same size holds only while every petak is the same
  size, which stopped being true the moment each took its measurements from
  its own patch of ground. Both ends of a pair are held to a hair under the
  fraction it returns, because that fraction is where they exactly touch and
  two petak drawn edge to edge read as one shape. Only the lower clamp can let
  them overlap, and it is there so a prism dropped on top of another still
  draws something.
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

## Weather scene

- Cloud has no sensor. `App\Support\SkyState` infers it from illuminance
  against what a clear sky would deliver at the current solar elevation
  (`118000 * sin(elevation)`), and reads the rain gauge beside it — that pair
  is what separates a dark dry morning (`mendung`) from a dark wet one
  (`rintik`). Below ~1500 lux of expected light the inference is refused and
  the state is `malam`, because there is nothing to compare against; measured
  rain still outranks the hour.
- Every state carries a `reason` sentence naming both numbers. An illustration
  that changes the picture has to say what it read, or nobody can check it.
- `site.sky` merges that reading with the reader's what-if (`skyScenario`).
  The presets come from the server (`sky.presets`), so the browser cannot paint
  a scene the service would not have produced, and a forced state is marked
  `simulated` — the same convention as the simulated clock. It never writes.
- Cloud is graded into `stageFilter` (less light, less colour) and only the
  remaining grey is painted by `.sky-veil`; rain takes a second helping of
  both there, more colour than light, which is the difference between
  `mendung` and `hujan` at the same illuminance. Everything on top is paint
  plus one transform — it sits over a sphere that is already animating, so
  nothing there may force a re-layout.
- Three layers, each for one cue. `.sky-veil` is weighted to the top of the
  frame, because overcast lifts the sky towards white long before it darkens
  the ground — a flat grey over the whole picture reads as a filter switched
  on. `.sky-haze` is aerial perspective: distance goes first in weather, so
  the grey collects around the horizon and the near bank keeps its contrast.
  `.sky-rain` is the two streak sheets.
- Drizzle and a downpour are not one picture at two opacities: `site.rainSheets`
  sizes each sheet from the rain figure — drizzle short, slow and close to fog,
  a downpour long, fast and further apart. Speed is a distance per second, not
  a duration, because the loop length changes with the drop; a fixed duration
  would make heavy rain fall slower. A drop is dense at its head and trails off
  behind it, which the mask's stops draw.
- A drop is one soft radial blob in its own tile; the tiles are drawn upright
  and the whole sheet is rotated (`--tilt`), so the fall runs down a streak and
  the loop translates by exactly one tile (`--fall`). Two earlier shapes were
  wrong in instructive ways: an angled `repeating-linear-gradient` tiled with
  `background-size` gave hard 1px diagonals that aliased into moire with a seam
  crossing the screen every cycle (television static), and a column gradient
  cut by one `mask-image` put every column's drops on the same rows, which
  combed. Three drop layers a third of a tile apart is what staggers them, and
  `--cell` is therefore three times the spacing the reader sees.

- One partial (`partials/sky-layers`) serves the twin stage and the page
  backdrop. Adding a third surface means including it, not copying it.

## Icons

- One file, `resources/views/components/icon.blade.php`: a name-to-path map
  rendered into a 24x24 stroked SVG (`stroke-width` 1.7, round caps). Adding a
  glyph means adding one line; nothing else changes.
- Pick the name for the meaning, not the picture — a bell standing in for a
  conversation, or a rotated back-arrow standing in for a chevron, is how the
  set drifts. `x-icon` falls back to `sensor` when a name is unknown, so a typo
  shows up as the wrong glyph rather than a blank space.

## Instrument catalogue

- The parameter lists for the ADR prisms, the vibrating-wire piezometer and the
  spillway gates are matched against a real installation — the Ciawi dump on
  the build machine (`parameter_prisma`, `parameter_avw`, `parameter_pintu`).
  Only the *catalogue* is taken from there: names, units, kinds. The numbers
  stay `ReadingSimulator`'s, because another dam's measurements are that dam's.
- What that alignment settled: a vibrating wire reports its head as **mH2O**,
  not metres; and an AWGC reports **three-phase motor current per leaf**
  beside the opening, which is the channel an operator watches for a hoist
  going out of balance; and the opening is a **length in cm**, not a percentage
  — the hoist reports how far the leaf is up, and that is what a person says
  when they open a gate. The fraction of the stroke is the derived figure.
- A leaf's stroke is `meta.height_cm` on its marker, and it bounds everything:
  the simulator, the slider, and the order. An order past it is **refused**,
  never trimmed — quietly reducing 140 cm to 100 would record an order nobody
  gave and leave the operator believing the gate is going somewhere it is not.

## Spillway gates

- A gate order is a record, not a reading. `gate_commands` says who asked for
  what opening and when — the part a flood report has to be able to quote —
  while `gate_opening_1..3` say where the leaves actually are. Keeping them
  apart is also what lets the simulator drive a gate towards an order instead
  of overwriting it with the next generated row.
- `ReadingSimulator` is otherwise a pure function of (metric, timestamp); the
  gates are the documented exception, and they have to be, because a gate an
  operator opened stays open. Orders are read once per station and cached, so
  the history is still reproducible — it just depends on the orders as well as
  the clock. Call `forgetOrders()` after writing one.
- A leaf travels to its order over `GATE_TRAVEL` and then holds, which is what
  puts a ramp on the chart instead of a step. `orderGate()` posts this
  instant's opening and target straight away so the panel shows the order at
  once — with the opening still at the sill, because the order has been given
  and the gate has not moved.
- The waves behind the three leaves are seeded per *gate*, not per metric.
  Seeded per metric, `gate_opening` and the three parts under it were three
  different noises and the headline disagreed with its own leaves.
- Percent and centimetres are both printed, and neither alone is the answer:
  the percentage is what the hoist reports, the stroke (`meta.height_cm`) is
  what the water sees. A leaf with no recorded stroke prints no centimetres
  rather than a guess.
- The control is a dialog opened from the stage's top-right chrome, beside the
  sky clock (or by picking a leaf on the sphere), teleported to `body` like
  every other dialog. It sits there rather than on the bottom bar because the
  bottom bar belongs to the stage and this belongs to one station — it is the one
  control in the app that writes an order to a structure, so it asks for the
  reader's whole attention instead of sitting in a column they scroll past,
  and the summary panel stays what it is: a reading of the station.
- Three bays stand eight degrees apart, so a gate chip has to be narrower than
  that or the three run into one another. Everything on it is stacked rather
  than laid side by side, and the centimetres wait for `sphere--near` — the
  same rule the prism figures follow, for the same reason.
- The bay is a projected polygon and the filled shape inside it is the
  **leaf**, riding up out of the bay as the gate opens and covering the whole
  bay when it is shut — the way the real one moves. It was first drawn as the
  gap *under* the leaf, which put the shape on screen travelling the opposite
  way to the thing it stands for: a gate closing looked like a gate opening.
  The leaf is steel-coloured for the same reason; blue would have said water
  where the steel is.

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

- The browser icon is the ministry logo, scaled by `tools/build_favicon.py`
  into `assets/icon/logopu-32.png`, a 180px apple-touch icon and
  `favicon.ico`. Not the 598px original: sixty-five kilobytes for something
  drawn at sixteen pixels, paid on every page load. Re-run the script after
  replacing `assets/logopu.png`.
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

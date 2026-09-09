import { postJson } from '../lib/api.js';
import { statusColor } from '../lib/format.js';
import { iconSvg } from '../lib/icons.js';
import { gateHtml, hotspotHtml, loadPsv, plotHtml, sectionHtml, sectionName, stakeHtml, VECTOR_SCALE } from './panorama.js';

const DEG = Math.PI / 180;

/** How long a pin keeps one parameter on screen before naming the next. */
const CAPTION_EVERY = 4200;

/** How long the turns take to sweep across all the pins. */
const CAPTION_SPREAD = 900;
const PHASES = ['night', 'dawn', 'day', 'dusk'];
/**
 * How far the cloud deck slides for one degree of camera bearing, in pixels.
 *
 * Cloud is far enough behind the dam to travel with the camera rather than
 * with the sphere, but the deck is paint over the picture and not a second
 * sphere — there is no camera model to derive this from, so it is tuned at the
 * framing the stage opens on and left alone.
 */
const CLOUD_PARALLAX = 7.5;

/** How far up or down the deck may be carried before its band runs out. */
const CLOUD_LIFT = 330;

/** Cover at which the stage swaps to the overcast render of the dam. */
const OVERCAST = 0.55;


/**
 * The digital twin stage: one Photo Sphere Viewer showing the dam's base
 * panorama with a pin for every station, which swaps to a station's own
 * panorama when a pin is picked.
 *
 * A single viewer serves both states — a second WebGL context for the station
 * view would double the memory for the same picture — so this component owns
 * the sphere, its station pins and its hotspots at once.
 */
export default function twinSphere() {
    // Held outside the returned object: Alpine proxies component state, and the
    // viewer keeps three.js matrices that must not be observed through a Proxy.
    const psv = {
        viewer: null, markers: null, autorotate: null,
        showing: null, pending: null, flight: null, phaseTimer: null,
    };

    return {
        ready: false,

        /** Only the first sphere shows a veil; later swaps are cross-faded. */
        loading: true,

        /** Turning towards the pin that was picked, before the panorama swaps. */
        flying: false,
        flyingTo: null,

        /**
         * A pin the stage is pointing out — from a search hit, say. It wears
         * its caption even when labels are switched off, because otherwise the
         * camera turns to a place and leaves the reader to guess which dot it
         * meant.
         */
        highlighted: null,

        /** True only while the camera turns; the pins stay up that long. */
        pointing: false,

        /** Set while the pins fade out ahead of a panorama swap. */
        departing: false,

        /** Which time-of-day texture the base sphere currently wears. */
        phase: null,
        rotating: true,

        /** The viewer's own 0-100 zoom, as a fraction — the readout on stage. */
        zoom: 0,

        /** Where the camera looks, in degrees — drives the glass compass. */
        heading: 0,

        /** How far above or below the horizon it looks, in degrees. */
        tilt: 0,

        /** Where north sits in the panorama on screen, in degrees. */
        northOffset: 0,

        /**
         * The compass dial's angle, unwrapped.
         *
         * `bearing` is 0-360, so it steps from 359 to 0 — and a CSS transition
         * reads that as turning 359 degrees backwards. This one keeps counting
         * (…358, 359, 360, 361…), so the dial always takes the short way.
         */
        dialAngle: 0,
        activeHotspot: null,

        /** Marker captions; off leaves just the pins. */
        showLabels: true,

        /**
         * Which pins the stage carries: every station, or only the ones that
         * measure water (level, quality, seepage, gate). The bottom bar used to
         * dispatch this as an event nobody listened to.
         */
        pinFilter: 'sensor',

        /** Pin placement: drag a station to where it really stands. */
        editMarkers: false,
        draggingMarker: null,

        /** The same handle inside a station panorama, for its hotspots. */
        draggingHotspot: null,

        /** The stake being dragged, when it is one stake and not the line. */
        draggingStake: null,

        /** The prism whose figure the panel is printing, if one was picked. */
        activeStake: null,

        /** Whether every prism shows which way it has moved. */
        showVectors: false,

        /** Whether the figures under the prisms are held open at any zoom. */
        showFigures: false,

        /** Which parameter the station pins are naming this turn. */
        captionStep: 0,

        /**
         * Where the cloud deck sits for the bearing the camera is on.
         *
         * The deck repeats every `cloudTile` pixels, so the shift is wrapped
         * into one tile: the sky keeps travelling for as long as the reader
         * keeps turning, and the join never arrives.
         */
        get cloudShift() {
            const tile = this.$store.site.cloudTile;
            const across = -this.heading * CLOUD_PARALLAX;
            const down = this.tilt * CLOUD_PARALLAX;

            return {
                '--sky-x': `${(((across % tile) + tile) % tile).toFixed(1)}px`,
                // Looking up brings the horizon down the screen and the sky
                // with it. There is no wrap for this one, so it is held inside
                // the overhang the band was given.
                '--sky-y': `${Math.max(-CLOUD_LIFT, Math.min(CLOUD_LIFT, down)).toFixed(1)}px`,
            };
        },

        /**
         * How much night to paint over the sphere. The panorama was shot in
         * daylight, so the solar grade alone cannot carry dusk and night.
         */
        get nightWash() {
            const weights = this.$store.site.phaseWeights;

            // The renders carry their own night, complete with the lamps along
            // the crest, so this is only a whisper to cover the minutes between
            // one texture and the next.
            return (weights.night ?? 0) * 0.07 + (weights.dusk ?? 0) * 0.04;
        },

        /**
         * The solar grade, at half strength.
         *
         * Each sphere is already lit for its hour; applying the full grade on
         * top would darken the night render twice over. Half keeps the minutes
         * between two textures moving without fighting the picture.
         */
        get sphereFilter() {
            const grade = this.$store.site.scene?.grade ?? {};
            const half = (value) => (1 + (value ?? 1)) / 2;

            return `brightness(${half(grade.brightness)}) contrast(${half(grade.contrast)}) saturate(${half(grade.saturate)})`;
        },

        /** What the sphere currently shows: the base panorama or a station. */
        get stationView() {
            return this.$store.viewer.open;
        },

        /** One control, two jobs — it names the one the current view has. */
        get placeLabel() {
            if (this.editMarkers) {
                return 'Selesai atur posisi';
            }

            return this.stationView ? 'Atur posisi titik panorama' : 'Atur posisi penanda stasiun';
        },

        get placeHint() {
            return this.stationView
                ? 'Seret titik — patok geser, alat, atau tautan — ke letaknya di panorama. Tersimpan otomatis.'
                : 'Seret penanda ke titik aslinya di panorama — tersimpan otomatis.';
        },

        initSphere() {
            try {
                this.showLabels = window.localStorage.getItem('twin.labels') !== '0';
                this.showFigures = window.localStorage.getItem('twin.figures') === '1';
            } catch {
                this.showLabels = true;
            }

            this.$watch('$store.site.environment', () => this.mount());
            /*
            | Only the base view is built from `site.markers`, and that store
            | is replaced on every poll. Re-syncing inside a station rebuilt
            | all of its hotspots — every petak, stake and caption — a few
            | seconds apart for no change at all, which dropped whatever
            | tooltip the reader was holding open and threw away the DOM the
            | keyboard was standing on.
            */
            this.$watch('$store.site.markers', () => {
                if (!this.stationView) {
                    this.syncPins();
                }
            });
            this.$watch('stagePhase', () => this.applyPhase());
            this.$watch('$store.viewer.station', (station) => {
                if (station?.panorama?.url) {
                    this.show(station);
                }
            });
            this.$watch('$store.viewer.open', (open) => {
                if (!open) {
                    this.show(this.base);
                    // The sky may have turned while the station was open.
                    this.applyPhase();
                }
            });

            // The viewer rejects its own transition promise when a swap is
            // interrupted; that one is expected, everything else still reports.
            window.addEventListener('unhandledrejection', (event) => {
                if (event.reason?.isFromCancelledTransition) {
                    event.preventDefault();
                }
            });

            this.bindDragging();
            this.mount();

            /*
            | The pins walk through their parameters rather than rebuilding.
            | Re-rendering a marker restarts the dot's breathing animation, and
            | fifteen of those restarting in step every few seconds is a twitch
            | across the whole stage — so only the two lines of text change,
            | found through our own markup rather than the plugin's ids.
            */
            psv.turns = [];
            psv.captions = window.setInterval(() => {
                psv.turns.splice(0).forEach(window.clearTimeout);
                this.cycleCaptions();
            }, CAPTION_EVERY);
        },

        /** Show the next parameter on every pin that has more than one. */
        cycleCaptions() {
            const sphere = this.$refs.sphere;

            // Nothing to see: inside a station, mid-flight, labels off, or a
            // tab nobody is looking at.
            if (!sphere || !this.ready || this.stationView || this.flying
                || !this.showLabels || document.hidden) {
                return;
            }

            this.captionStep += 1;

            const turning = this.$store.site.markers
                .filter((marker) => (marker.readings?.length ?? 0) > 1)
                .map((marker) => ({
                    marker,
                    pin: sphere.querySelector(`.sphere-pin[data-station="${marker.code}"]`),
                }))
                .filter(({ pin }) => pin?.querySelector('.sphere-pin__value'));

            /*
            | The turns are spread rather than fired together. Fifteen plates
            | changing on the same frame is one flicker across the whole stage;
            | a beat apart they read as a stage that is alive. The spread is
            | capped well inside the interval, so no cycle catches the next.
            */
            const beat = Math.min(70, CAPTION_SPREAD / Math.max(1, turning.length));

            turning.forEach(({ marker, pin }, index) => {
                const reading = pinReading(marker, this.captionStep);
                const turn = () => {
                    const value = pin.querySelector('.sphere-pin__value');
                    const name = pin.querySelector('.sphere-pin__param');

                    if (!value || !name) {
                        return;
                    }

                    value.textContent = reading.value ?? '';
                    value.style.color = statusColor(reading.status ?? marker.status);
                    name.textContent = reading.label ?? '';

                    // Replay the fade: the same element, so the class has to go
                    // and come back for the animation to run again.
                    pin.classList.remove('sphere-pin--turned');
                    void pin.offsetWidth;
                    pin.classList.add('sphere-pin--turned');
                };

                if (index === 0) {
                    turn();

                    return;
                }

                psv.turns.push(window.setTimeout(turn, index * beat));
            });


        },

        /** Base panorama descriptor from `/api/environment`. */
        get base() {
            return this.$store.site.environment?.stage?.base ?? null;
        },

        /** A new panorama can put north somewhere else; the dial follows. */
        setNorthOffset(offset) {
            this.dialAngle += (offset ?? 0) - this.northOffset;
            this.northOffset = offset ?? 0;
        },

        /** Compass bearing the camera is pointing at, 0-360 from north. */
        get bearing() {
            return (((this.heading + this.northOffset) % 360) + 360) % 360;
        },

        /** Bearing as degrees plus the Indonesian point of the compass. */
        get compassLabel() {
            const points = ['U', 'TL', 'T', 'TG', 'S', 'BD', 'B', 'BL'];

            return `${Math.round(this.bearing)}° ${points[Math.round(this.bearing / 45) % 8]}`;
        },

        /**
         * Which way a panorama opens, in degrees of yaw.
         *
         * Openings are compass bearings rather than raw yaws, so the framing
         * stays put when `north_offset` is corrected: yaw is measured from the
         * picture, the bearing from north. The base panorama takes the dam's
         * own (`dam.stage.default_bearing`); a station takes the one on its
         * record (`panorama_bearing`) — the direction its reader is meant to
         * be facing, which is a survey figure, not a framing preference. A
         * station without one falls back to its raw yaw.
         */
        openingYaw(panorama, isBase) {
            const bearing = isBase
                ? this.$store.site.environment?.stage?.default_bearing
                : panorama?.bearing;

            if (bearing !== null && bearing !== undefined) {
                return bearing - (panorama?.north_offset ?? 0);
            }

            return panorama?.yaw ?? 0;
        },


        /**
         * The framing the stage rests at, from `dam.stage.default_zoom`.
         *
         * Written-out levels used to assume a resting zoom of 45; with the
         * stage opening at its widest they would have been a lean *in* where
         * the choreography calls for standing still.
         */
        restingZoom() {
            return this.$store.site.environment?.stage?.default_zoom ?? 45;
        },

        /** A step closer than the resting framing, clamped to the viewer's range. */
        closerZoom(step) {
            return Math.min(100, this.restingZoom() + step);
        },

        /**
         * Point the idle drift at a sweep instead of a full turn.
         *
         * Two keypoints either side of the panorama's own framing: the plugin
         * walks to one, then back to the other along the short arc, so the
         * camera never travels far enough to reach the seam where the render
         * was joined. The pitch is the framing's own, which keeps the drift
         * level — it goes right and left, never up and down.
         */
        driftAround(yaw, pitch) {
            if (!psv.autorotate) {
                return;
            }

            const arc = (this.$store.site.environment?.stage?.drift_arc ?? 55) * DEG;

            // Right first, then back to the left: the order is the itinerary.
            psv.autorotate.setKeypoints([
                { position: { yaw: yaw + arc, pitch } },
                { position: { yaw: yaw - arc, pitch } },
            ]);
        },

        /** Re-centre the sweep on whatever the camera is framing now. */
        recentreDrift() {
            if (!psv.viewer) {
                return;
            }

            const { yaw, pitch } = psv.viewer.getPosition();

            this.driftAround(yaw, pitch);
        },

        /** The viewer's own zoom level, not the mirrored state (for debugging). */
        get zoomLevel() {
            return psv.viewer?.getZoomLevel?.() ?? null;
        },

        /** Which texture the sphere is actually wearing (handy when debugging). */
        get textureUrl() {
            const panorama = psv.viewer?.config?.panorama;

            return typeof panorama === 'string' ? panorama : null;
        },

        /** The time of day the stage should be wearing right now. */
        get sunPhase() {
            const weights = this.$store.site.phaseWeights;

            return PHASES.reduce(
                (best, phase) => ((weights[phase] ?? 0) > (weights[best] ?? 0) ? phase : best),
                'day',
            );
        },

        /** Whether a texture name is a weather render rather than an hour. */
        weatherPhase(name) {
            return Boolean(name) && !PHASES.includes(name);
        },

        /**
         * The texture the stage actually wears: the hour, unless the sky is
         * covered enough that there is a render of it.
         *
         * The sky state's own code comes first, so building `rintik` or
         * `hujan` one day is a file and a line of config and nothing here.
         * Failing that, a covered sky falls back to the overcast render — a
         * downpour under an overcast sphere with the rain drawn over it is
         * far nearer the truth than a downpour under a sunny one.
         *
         * Weather renders exist for daylight only, and that is the whole of
         * the rule — dawn, dusk and night keep their own spheres, because
         * cloud at those hours is a change of colour the paint can still
         * carry. `phases` is missing a name entirely when the file was never
         * built, so a site without the render simply never reaches this.
         */
        get stagePhase() {
            const sun = this.sunPhase;
            const sky = this.$store.site.sky ?? {};
            const phases = this.base?.phases ?? {};

            if (sun !== 'day') {
                return sun;
            }

            if (this.weatherPhase(sky.code) && phases[sky.code]) {
                return sky.code;
            }

            return (sky.cloud ?? 0) >= OVERCAST && phases.mendung ? 'mendung' : sun;
        },

        /**
         * Whether the weather belongs to the picture rather than to the paint.
         *
         * This asks where the stage is *going*, not what it is wearing: the
         * swap is debounced and then cross-fades, and for those two seconds
         * the painted lid used to rise over a sphere that was still sunny and
         * sink again once the render landed. Grey haze blooming over a blue
         * sky and then clearing is not weather arriving — it reads as smoke.
         * Nothing is painted from the moment the answer is a render; the
         * picture changing is the whole of the transition.
         */
        get bakedSky() {
            return !this.stationView && this.weatherPhase(this.stagePhase);
        },

        /** Texture set for one phase, falling back to daylight. */
        phaseAsset(phase) {
            return this.base?.phases?.[phase] ?? this.base?.panorama ?? null;
        },

        /**
         * Swap the base sphere for the texture of the current phase, skipped
         * whenever a station panorama is on screen.
         *
         * Scrubbing the clock crosses several phases in a second, so an hour
         * is debounced. A change of weather is a button press and waits for
         * nothing: four hundred milliseconds of a sky that has not moved is
         * four hundred milliseconds of the reader deciding the control missed
         * the click — and the rain starts falling in the same frame they
         * pressed, so there is nothing left to hide the pause behind.
         */
        applyPhase(after = null) {
            window.clearTimeout(psv.phaseTimer);

            const swap = this.weatherPhase(this.stagePhase) || this.weatherPhase(this.phase);

            psv.phaseTimer = window.setTimeout(() => {
                const phase = this.stagePhase;

                if (!psv.viewer || !this.ready || this.flying) {
                    /*
                    | The weather can turn while the stage is still opening or
                    | while an arrival is in the air. Dropping the change there
                    | left the sphere on the wrong sky for the rest of the
                    | session — the reader picked `mendung` a second too early
                    | and the dam stayed sunny — so it waits for its turn
                    | instead of being thrown away. The retry names its own
                    | wait: a weather swap is scheduled for the next tick, and
                    | re-arming that for the length of an arrival is a spin.
                    */
                    this.applyPhase(400);

                    return;
                }

                // Inside a station there is no base sphere to swap; coming back
                // out calls this again.
                if (this.stationView) {
                    return;
                }

                if (psv.showing?.code !== this.base?.code || phase === this.phase) {
                    return;
                }

                const asset = this.phaseAsset(phase);

                if (!asset?.url) {
                    return;
                }

                // A change of weather is somebody pressing a button, not the
                // hour turning: the slow fade below is for a valley whose
                // light should change the way it does outside, and waiting
                // nearly three seconds for an answer to a click reads as the
                // control having missed it.
                const asked = this.weatherPhase(phase) || this.weatherPhase(this.phase);
                const preview = asset.preview ?? asset.url;

                /*
                | The sharp one downloads while the fade runs, through the
                | loader so `setPanorama` reuses it rather than fetching the
                | file twice. Without this the HD only starts once the fade is
                | over, and the sphere holds the preview for as long as six
                | hundred kilobytes take.
                */
                if (asset.url !== preview) {
                    psv.viewer.textureLoader.preloadPanorama(asset.url).catch(() => {});
                }

                this.phase = phase;

                psv.viewer
                    .setPanorama(preview, {
                        showLoader: false,
                        // Slow on the real clock — the light of the valley
                        // should change the way it does outside. Scrubbing runs
                        // through whole phases, so it gets a quicker fade.
                        transition: {
                            effect: 'fade',
                            rotation: false,
                            speed: asked || this.$store.site.time.mode === 'custom' ? 900 : 2600,
                        },
                    })
                    .then(() => psv.viewer.setPanorama(asset.url, { showLoader: false, transition: false }))
                    .catch(() => {});
            }, after ?? (swap ? 0 : 400));
        },

        /**
         * Pull the weather renders into the viewer's cache while the stage is
         * idle.
         *
         * `setPanorama` does not begin its cross-fade until the texture has
         * landed, so an unwarmed render is a button that does nothing for as
         * long as the file takes — and the whole of that wait falls between
         * the reader's click and the first frame of the sky moving. Previews
         * only: they are a sixth of the bytes and the sharpening pass is
         * warmed by the swap itself.
         */
        warmWeather() {
            Object.entries(this.base?.phases ?? {})
                .filter(([name]) => this.weatherPhase(name))
                .forEach(([, asset]) => {
                    psv.viewer?.textureLoader
                        .preloadPanorama(asset.preview ?? asset.url)
                        .catch(() => {});
                });
        },

        /**
         * The panorama the stage should be *built* with. A deep link
         * (`/digital-twin/{station}`) — or a pin picked while the viewer was
         * still being imported — means the reader asked for a station, so
         * opening on the base dam and then flying there shows them a picture
         * they did not ask for and an arrival nobody triggered. The
         * choreography belongs to a click on the stage, not to a refresh.
         */
        entryStation() {
            const code = psv.pending?.code ?? (this.$store.viewer.open ? this.$store.viewer.code : null);

            if (!code || code === this.base?.code) {
                return null;
            }

            // Either the parked request or the marker: both carry the
            // panorama, so neither waits on `/api/stations/{code}`.
            const target = psv.pending ?? this.$store.site.markerByCode(code);

            return target?.panorama?.url
                ? { code, name: target.name, panorama: target.panorama }
                : null;
        },

        async mount() {
            if (psv.viewer || !this.base?.panorama?.url) {
                return;
            }

            const { Viewer, MarkersPlugin, AutorotatePlugin } = await loadPsv();

            // Guard: environment can refresh while the import is in flight.
            if (psv.viewer) {
                return;
            }

            const entry = this.entryStation();
            const target = entry ?? this.base;
            const isBase = !entry;

            psv.showing = target;
            // The weather the stage opens on, not just the hour: a page loaded
            // under a covered sky used to build the sunny sphere and stay on
            // it, because the watcher only ever hears about a *change*.
            this.phase = this.stagePhase;
            this.setNorthOffset(target.panorama.north_offset ?? 0);

            // The base sphere has one texture per time of day; a station has
            // the one photograph.
            const opening = isBase ? this.phaseAsset(this.phase) : target.panorama;

            psv.viewer = new Viewer({
                container: this.$refs.sphere,
                panorama: opening.preview ?? opening.url,
                caption: target.name,
                navbar: false,
                defaultYaw: this.openingYaw(target.panorama, isBase) * DEG,
                defaultPitch: (target.panorama.pitch
                    || (isBase ? this.$store.site.environment?.stage?.default_pitch : 0)
                    || 0) * DEG,
                defaultZoomLvl: this.$store.site.environment?.stage?.default_zoom ?? 45,
                minFov: 24,
                maxFov: 100,
                moveSpeed: 1,
                // Let a drag glide to a stop instead of stopping dead.
                moveInertia: 0.9,
                mousewheelCtrlKey: false,
                touchmoveTwoFingers: false,
                loadingTxt: 'Memuat panorama…',
                plugins: [
                    [MarkersPlugin, { markers: [] }],
                    // The stage is never quite still: it drifts slowly until
                    // touched, and picks the drift back up a few seconds later.
                    /*
                    | `startFromClosest: false` is what makes the sweep set off
                    | to the right: the plugin walks the keypoints in the order
                    | they are given instead of picking the nearest one, and
                    | the right-hand point is first.
                    */
                    [AutorotatePlugin, {
                        autostartDelay: 4000,
                        autostartOnIdle: true,
                        autorotateSpeed: '0.11rpm',
                        startFromClosest: false,
                    }],
                ],
            });

            psv.markers = psv.viewer.getPlugin(MarkersPlugin);
            psv.autorotate = psv.viewer.getPlugin(AutorotatePlugin);

            psv.viewer.addEventListener('ready', () => {
                this.ready = true;
                this.loading = false;

                this.recentreDrift();

                /*
                | Seed the mirrored camera state from the viewer itself.
                | `position-updated` and `zoom-updated` only fire on a change,
                | so the opening framing would otherwise never reach the
                | compass or the zoom readout — the dial read north while the
                | camera was already looking north-east.
                */
                const opening = psv.viewer.getPosition();

                this.dialAngle += (opening.yaw / DEG) - this.heading;
                this.heading = opening.yaw / DEG;
                this.tilt = opening.pitch / DEG;
                this.zoom = psv.viewer.getZoomLevel() / 100;

                // The weather the reader has not asked for yet, in the cache
                // before they ask.
                this.warmWeather();

                // A panorama asked for while the viewer was still being built
                // (deep link, or a very quick click) gets its turn now.
                const pending = psv.pending;
                psv.pending = null;

                if (pending && pending.code !== psv.showing?.code) {
                    this.show(pending);

                    return;
                }

                this.syncPins();
                this.loadHd(psv.showing);
            });

            psv.viewer.addEventListener('zoom-updated', ({ zoomLevel }) => {
                // The readout is the viewer's own scale: 0 at the widest
                // framing the stage opens on, 100 fully zoomed in. It used to
                // be divided by the old resting level, so the stage claimed
                // 100% before anyone had touched the stepper.
                this.zoom = zoomLevel / 100;
            });

            psv.viewer.addEventListener('position-updated', ({ position }) => {
                const heading = position.yaw / DEG;

                // Shortest way round, then add it to the running total.
                this.dialAngle += (((heading - this.heading) % 360) + 540) % 360 - 180;
                this.heading = heading;
                this.tilt = position.pitch / DEG;
            });

            psv.viewer.addEventListener('size-updated', () => this.tuneMoveSpeed());
            this.tuneMoveSpeed();

            psv.markers.addEventListener('select-marker', ({ marker }) => this.onMarker(marker.id));
        },

        /**
         * Point the sphere at a panorama: the base one, or a station's.
         * The small preview texture goes up first and is replaced by the full
         * one in `loadHd()`, so the picture sharpens instead of blocking.
         */
        async show(target) {
            if (!target?.panorama?.url) {
                return;
            }

            if (!psv.viewer) {
                psv.pending = target;
                this.loading = true;

                return;
            }

            const same = psv.showing?.code === target.code;
            psv.showing = target;
            this.activeHotspot = null;
            this.activeStake = null;
            this.setNorthOffset(target.panorama?.north_offset ?? 0);

            if (same) {
                this.syncPins();

                return;
            }

            /*
            | The base sphere has one texture per time of day, and one more per
            | weather it has a render of. It has to be asked for by
            | `stagePhase`: on `sunPhase` the way back out of a station landed
            | on the plain daylight dam under a covered sky, and the catch-up
            | swap a moment later made the return two cross-fades with the
            | wrong weather in between.
            */
            const isBase = target.code === this.base?.code;
            const asset = isBase ? this.phaseAsset(this.stagePhase) : target.panorama;

            if (isBase) {
                this.phase = this.stagePhase;
            }

            const preview = asset.preview ?? asset.url;

            // Download into the viewer's own cache while the camera is still
            // turning: doing it through the loader (rather than an Image of our
            // own) means `setPanorama` reuses it instead of fetching twice.
            const warm = psv.viewer.textureLoader.preloadPanorama(preview);

            // The sharp one follows straight behind it. Without this the HD only
            // starts downloading after the fade, so the sphere sits blurred for
            // as long as the file takes.
            if (asset.url !== preview) {
                psv.viewer.textureLoader.preloadPanorama(asset.url).catch(() => {});
            }

            // Hand over while the camera is still moving: the cross-fade picks
            // the motion up from there, so there is no pause between the two.
            await Promise.all([settle(warm), Promise.race([settle(psv.flight), wait(560)])]);
            psv.flight = null;

            // The approach is over. Let the pins fade with the picture rather
            // than blink out of existence a frame before it.
            this.departing = true;
            await wait(220);
            this.pointing = false;
            this.flyingTo = null;
            this.syncPins();

            if (psv.showing?.code !== target.code) {
                return;
            }

            // No rotation in the transition: the camera has just been turned to
            // the pin, and letting it *animate* to the new panorama's framing
            // mid-fade is the swing across two pictures that reads as a blink.
            const resting = this.restingZoom();

            // Arriving is a step *into* the place: the picture cross-fades and
            // the new scene opens up towards the camera. Nothing ever pulls
            // back on the way in — that reversal is what made the move feel
            // like walking out.
            const swap = psv.viewer
                .setPanorama(preview, {
                    caption: target.name,
                    showLoader: false,
                    transition: { effect: 'fade', rotation: false, speed: 1000 },
                    /*
                    | The station opens facing its own bearing, and it is
                    | facing it the moment the picture appears. `position`
                    | with `rotation: false` is what makes that free: the
                    | viewer pre-rotates the incoming sphere so the requested
                    | framing already sits where the camera is pointing, then
                    | swaps camera and sphere together once the fade is over.
                    | Nothing turns on screen, so there is no swing across two
                    | pictures at once and no turn to watch afterwards.
                    */
                    position: {
                        yaw: this.openingYaw(target.panorama, isBase) * DEG,
                        pitch: (target.panorama?.pitch ?? 0) * DEG,
                    },
                    // Both ends of the trip land on the resting framing. The
                    // stage opens at its widest (`dam.stage.default_zoom`), so
                    // there is nothing to lean into and nothing to give back —
                    // the arrival is the turn plus the cross-fade, and the
                    // stepper is what gets closer afterwards.
                    zoom: resting,
                })
                .catch(() => {});

            // The chrome must not hang on that promise: a throttled tab (or an
            // interrupted fade) can leave it pending long after the picture has
            // already changed, and the station banner would never appear.
            await Promise.race([swap, wait(1100)]);

            this.flying = false;
            this.departing = false;

            if (psv.showing?.code !== target.code) {
                return;
            }

            this.loading = false;
            this.syncPins();

            // The sweep belongs to the picture on screen, not the one before it.
            this.recentreDrift();

            // Decoding the full sphere stalls a frame or two, so it waits for
            // the tail of the movement — but it does not wait for the fade's
            // promise, which can stall and leave the picture soft for ever.
            window.setTimeout(() => {
                if (psv.showing?.code === target.code) {
                    this.loadHd(target);
                }
            }, 300);
        },

        /**
         * Turn the camera towards a pin and lean in a little. Runs while the
         * station payload is still being fetched, so the move is free time.
         */
        pointAt(marker) {
            const sphere = marker?.sphere;

            if (!psv.viewer || !sphere) {
                return;
            }

            this.flying = true;
            this.pointing = true;
            this.flyingTo = marker.code;
            psv.autorotate?.stop();
            this.syncPins();

            // Turn and lean in as one move: chaining two animations makes the
            // camera stop in between, which is what reads as stuttering.
            psv.flight = settle(psv.viewer.animate({
                yaw: sphere.yaw * DEG,
                pitch: sphere.pitch * DEG,
                // The approach only turns: the push happens inside the fade.
                zoom: this.restingZoom(),
                duration: 780,
            }));
        },

        /** Swap the preview texture for the full one, camera untouched. */
        loadHd(target) {
            // The base sphere is whichever phase is on screen, not the plain
            // daylight photograph the station record points at.
            const panorama = target?.code === this.base?.code
                ? this.phaseAsset(this.phase ?? this.stagePhase)
                : target?.panorama;
            const url = panorama?.url;

            if (!psv.viewer || !url || url === panorama.preview) {
                return;
            }

            const code = target.code;

            psv.viewer
                .setPanorama(url, {
                    showLoader: false,
                    // A short fade rather than a hard swap: the picture is the
                    // same, so this reads as it coming into focus.
                    transition: { effect: 'fade', rotation: false, speed: 450 },
                    caption: target.name,
                    // Carry the framing over. Without it the swap falls back to
                    // the viewer's default zoom and yanks the camera back out
                    // of the step it just took into the station.
                    zoom: psv.viewer.getZoomLevel(),
                })
                .then(() => {
                    // A different panorama may have been opened meanwhile.
                    if (psv.showing?.code === code) {
                        this.syncPins();
                    }
                })
                .catch(() => {});
        },

        /* -------------------------------------------------------------- *
         |  Pins: stations on the base panorama, hotspots inside a station
         * -------------------------------------------------------------- */

        syncPins() {
            if (!psv.markers || !this.ready) {
                return;
            }

            /*
            | While the camera is still turning, the pins stay: the one being
            | approached is what the movement is pointing at.
            |
            | And nothing of the station goes up until the picture has landed.
            | `viewer.open` is true from the moment the pin is picked, so
            | without the `flying` guard the arrival drew ADR-02's petak and
            | prisms over the base dam — dashed plots lying across the
            | reservoir for the length of the cross-fade. The pins fade out
            | with `sphere--departing`; the hotspots have no such cover,
            | because they are not supposed to exist yet.
            */
            const showHotspots = this.stationView && !this.pointing && !this.flying;

            psv.markers.setMarkers(showHotspots ? this.hotspotMarkers() : this.stationMarkers());
        },

        /** Station types the "Ukuran Air" filter keeps. */
        get waterTypes() {
            return ['water_level', 'water_quality', 'sediment', 'seepage', 'gate', 'piezometer', 'observation_well'];
        },

        setPinFilter(mode) {
            this.pinFilter = mode;
            this.syncPins();
        },

        /**
         * The sections that open a drawing rather than a place.
         *
         * They stand on the base panorama beside the station pins, because a
         * buried instrument has nowhere of its own to be pointed at: what the
         * reader can actually look at is the cut through the dam it sits in.
         * The pin filter leaves them alone — a section is not a station and
         * has no type to filter by.
         */
        sectionMarkers() {
            return (this.base?.sections ?? []).map((section) => ({
                id: `section-${section.id}`,
                position: { yaw: section.yaw * DEG, pitch: section.pitch * DEG },
                html: sectionHtml(section, statusColor(section.status ?? 'normal')),
                anchor: 'center center',
                zIndex: 45,
                tooltip: { content: sectionName(section), position: 'top center' },
                data: { section: section.id },
            }));
        },

        stationMarkers() {
            const baseCode = this.base?.code;
            const water = this.waterTypes;

            return this.sectionMarkers().concat(this.$store.site.markers
                .filter((marker) => marker.code !== baseCode)
                .filter((marker) => this.pinFilter !== 'air' || water.includes(marker.type))
                .map((marker) => {
                    const color = statusColor(marker.status);

                    return {
                        id: `station-${marker.code}`,
                        position: {
                            yaw: (marker.sphere?.yaw ?? 0) * DEG,
                            pitch: (marker.sphere?.pitch ?? 0) * DEG,
                        },
                        html: pinHtml(
                            marker,
                            color,
                            marker.code === this.flyingTo,
                            marker.code === this.highlighted,
                            this.captionStep,
                        ),
                        anchor: 'center center',
                        zIndex: marker.code === this.$store.viewer.code ? 60 : 40,
                        data: { code: marker.code },
                    };
                }));
        },

        hotspotMarkers() {
            const station = this.$store.viewer.station;
            const metrics = new Map((station?.metrics ?? []).map((metric) => [metric.key, metric]));

            // Built once for the whole station: the ground a petak covers is
            // measured against prisms that belong to every line, not just its
            // own.
            const grid = this.plotGrid();

            return (station?.hotspots ?? []).flatMap((hotspot) => {
                if (hotspot.type === 'plot') {
                    return this.plotMarkers(hotspot, grid.get(hotspot.id));
                }

                if (hotspot.type === 'gate') {
                    return this.gateMarkers(hotspot, metrics);
                }

                // A section opens a drawing; it has no reading of its own to
                // print and nowhere for the camera to go.
                if (hotspot.type === 'piezo' && hotspot.section) {
                    return [{
                        id: `section-${hotspot.id}`,
                        // The record's own angles, not the section's copy of
                        // them: a drag writes them here first.
                        position: { yaw: hotspot.yaw * DEG, pitch: hotspot.pitch * DEG },
                        html: sectionHtml(hotspot.section, statusColor(hotspot.section.status ?? 'normal')),
                        anchor: 'center center',
                        zIndex: 45,
                        // The plate carries only the glyph, so its name lives
                        // here — and what is in it, which is the part worth
                        // reading before deciding to open the drawing.
                        tooltip: { content: sectionName(hotspot.section), position: 'top center' },
                        data: hotspot,
                    }];
                }

                const metric = hotspot.metric_key ? metrics.get(hotspot.metric_key) : null;
                const color = metric ? statusColor(metric.status) : '#47a6ff';
                const value = metric ? `${metric.formatted} ${metric.unit ?? ''}`.trim() : null;

                return [{
                    id: `hotspot-${hotspot.id}`,
                    position: { yaw: hotspot.yaw * DEG, pitch: hotspot.pitch * DEG },
                    html: hotspotHtml(hotspot, value, color),
                    anchor: 'bottom center',
                    ...(hotspot.description
                        ? { tooltip: { content: hotspot.description, position: 'top center' } }
                        : {}),
                    data: hotspot,
                }];
            });
        },

        /**
         * The ground every prism on the open station stands on.
         *
         * `plotGrid()` does the work; this only hands it the station's lines.
         * `swap` carries the shape a drag is showing, so the grid follows the
         * pointer rather than the record.
         */
        plotGrid(swap = null) {
            return plotGrid(
                (this.$store.viewer.station?.hotspots ?? []).filter((item) => item.type === 'plot'),
                swap,
            );
        },

        /**
         * One spillway gate: the bay it sits in, how far it is open, and the
         * figure printed above it.
         *
         * The bay is a projected polygon, so it lies on the structure rather
         * than facing the camera. The second polygon is the **leaf**, and it
         * moves the way the leaf does: down over the whole bay when the gate
         * is shut, riding up and out of the picture as it opens. Drawing the
         * gap underneath instead — which is what this did first — put the
         * shape on the screen moving the opposite way to the thing it stands
         * for, so a gate closing looked like a gate opening.
         */
        gateMarkers(hotspot, metrics) {
            const metric = metrics.get(hotspot.metric_key);
            const value = metric?.value;
            // How far the leaf is up, in centimetres of its own stroke.
            const height = Number(hotspot.meta?.height_cm ?? 0) || 100;
            const open = value === null || value === undefined
                ? null
                : Math.max(0, Math.min(height, Number(value)));
            const wide = Number(hotspot.meta?.cell_yaw ?? 5);
            const tall = Number(hotspot.meta?.cell_pitch ?? 6);
            const color = statusColor(metric?.status ?? 'normal');
            const sill = hotspot.pitch - tall / 2;
            const head = hotspot.pitch + tall / 2;
            const box = (bottom, top) => [
                [hotspot.yaw - wide / 2, top],
                [hotspot.yaw + wide / 2, top],
                [hotspot.yaw + wide / 2, bottom],
                [hotspot.yaw - wide / 2, bottom],
            ].map(([yaw, pitch]) => [yaw * DEG, pitch * DEG]);

            const markers = [{
                id: `cell-${hotspot.id}-bay`,
                polygon: box(sill, head),
                className: 'psv-cell psv-cell--bay',
                zIndex: 20,
                data: hotspot,
            }];

            // Nothing left to draw once the leaf is clear of the bay.
            if (open !== null && open < height) {
                markers.push({
                    id: `cell-${hotspot.id}-leaf`,
                    polygon: box(sill + (tall * open) / height, head),
                    className: 'psv-cell psv-cell--leaf',
                    zIndex: 21,
                    data: hotspot,
                });
            }

            markers.push({
                id: `hotspot-${hotspot.id}`,
                position: { yaw: hotspot.yaw * DEG, pitch: head * DEG },
                html: gateHtml(
                    hotspot,
                    open === null ? null : metric?.formatted ?? open.toFixed(1),
                    open === null ? null : Math.round((open / height) * 100),
                    color,
                ),
                anchor: 'bottom center',
                zIndex: 40,
                ...(hotspot.description
                    ? { tooltip: { content: hotspot.description, position: 'top center' } }
                    : {}),
                data: hotspot,
            });

            return markers;
        },

        /**
         * A row of monitoring petak: one petak per stake, and one caption.
         *
         * Each petak is a `polygon` marker — points in spherical coordinates,
         * so the plugin projects it and it lies on the slope instead of facing
         * the camera like a sticker. A flat rectangle over an oblique dam face
         * reads as pasted on.
         *
         * Everything is derived from the row's centre, so it moves as a piece
         * and `syncPins()` and a drag can share this function.
         */
        plotMarkers(hotspot, stakes) {
            if (!stakes?.length) {
                return [];
            }

            const codes = hotspot.stakes ?? [];

            return [
                ...stakes.flatMap((stake, index) => {
                    const reading = codes[index] ?? { code: `${hotspot.label} · patok ${index + 1}` };
                    // A prism with no reading has no status to wear: green
                    // would say the movement was measured and fine.
                    const color = reading.status ? statusColor(reading.status) : UNREAD;
                    const tooltip = reading.linear == null
                        ? reading.code
                        : `${reading.code} · ${reading.formatted} mm`;

                    return [
                        {
                            id: `cell-${hotspot.id}-${index + 1}`,
                            polygon: stake.cell.map(([cornerYaw, cornerPitch]) => [cornerYaw * DEG, cornerPitch * DEG]),
                            className: 'psv-cell',
                            /*
                            | The ground wears the status of the prism standing
                            | in it — green while the movement is within its
                            | band, amber, orange, then red as it crosses each
                            | threshold. Written as marker style rather than a
                            | class so the colour comes from the one palette
                            | (`statusColor`) instead of being restated in CSS.
                            |
                            | Fill kept faint and the stroke full: a petak is
                            | ground, and ground that shouts drowns the prism
                            | and the figure standing on it.
                            */
                            svgStyle: {
                                fill: color,
                                fillOpacity: 0.16,
                                stroke: color,
                                strokeOpacity: 0.95,
                            },
                            zIndex: 20,
                            data: hotspot,
                        },
                        {
                            id: `stake-${hotspot.id}-${index + 1}`,
                            position: { yaw: stake.yaw * DEG, pitch: stake.pitch * DEG },
                            html: stakeHtml(hotspot, reading, color, index + 1),
                            size: { width: 26, height: 26 },
                            anchor: 'center center',
                            zIndex: 30,
                            tooltip: { content: tooltip, position: 'top center' },
                            data: hotspot,
                        },
                    ];
                }),
                {
                    id: `hotspot-${hotspot.id}`,
                    position: (({ yaw, pitch }) => ({ yaw: yaw * DEG, pitch: pitch * DEG }))(plotCaption(stakes)),
                    html: plotHtml(hotspot, stakes.length),
                    anchor: 'bottom center',
                    zIndex: 40,
                    data: hotspot,
                },
            ];
        },

        onMarker(id) {
            if (this.draggingMarker || this.draggingHotspot) {
                return;
            }

            // A section is a drawing, not a place: nothing flies anywhere.
            if (String(id).startsWith('section-')) {
                this.$store.viewer.openSection(Number(String(id).replace('section-', '')));

                return;
            }

            if (String(id).startsWith('station-')) {
                if (this.editMarkers) {
                    return;
                }

                const code = String(id).replace('station-', '');
                const marker = this.$store.site.markerByCode(code);

                this.clearHighlight();

                this.pointAt(marker);

                // The marker already carries the panorama, so the sphere can
                // start swapping while the station payload is still loading.
                if (marker?.panorama?.url) {
                    this.show({ code, name: marker.name, panorama: marker.panorama });
                }

                this.$store.viewer.open360(code);

                return;
            }

            // In placement mode a hotspot is being moved, not read.
            if (this.editMarkers) {
                return;
            }

            const hotspot = this.hotspotFor(id);

            if (!hotspot) {
                return;
            }

            // Picking one prism means asking about that prism, not the row.
            this.activeStake = this.stakeFor(hotspot, id);

            // Picking a gate is asking what it is doing, which is a question
            // the panel answers next to the control that changes it.
            if (hotspot.type === 'gate') {
                this.$store.viewer.openGates(hotspot.meta?.gate ?? null);
            }

            if (hotspot.type === 'link' && hotspot.target?.code) {
                this.$store.viewer.open360(hotspot.target.code);

                return;
            }

            if (hotspot.metric_key) {
                this.$store.viewer.focusMetric(hotspot.metric_key);
            }

            this.activeHotspot = hotspot;
        },

        /**
         * The hotspot a marker id belongs to.
         *
         * A row draws three kinds of marker (`cell-`, `stake-`, `hotspot-`)
         * from one record, and picking any of them means the same thing: show
         * me that row of petak.
         */
        hotspotFor(id) {
            const key = Number(String(id).replace(/^(hotspot|cell|stake)-/, '').split('-')[0]);

            return (this.$store.viewer.station?.hotspots ?? []).find((item) => item.id === key);
        },

        /** The one prism a `stake-<hotspot>-<n>` marker stands for. */
        stakeFor(hotspot, id) {
            const match = /^stake-\d+-(\d+)$/.exec(String(id));

            return match ? (hotspot.stakes ?? [])[Number(match[1]) - 1] ?? null : null;
        },

        /** Arrows are noise until the reader is asking about direction. */
        toggleVectors() {
            this.showVectors = !this.showVectors;
        },

        /**
         * Hold the millimetre figures open whatever the zoom.
         *
         * They arrive with the zoom that makes room for them, because a line
         * running away from the camera crowds its far stakes to ten pixels
         * apart. Forcing them on is the reader's call to make: the far end of
         * a line will overlap, and that is the price of reading every prism at
         * once instead of leaning in.
         */
        toggleFigures() {
            this.showFigures = !this.showFigures;

            try {
                window.localStorage.setItem('twin.figures', this.showFigures ? '1' : '0');
            } catch {
                // A browser that refuses storage still gets the toggle.
            }
        },

        /** Pixels of arrow per millimetre, so the caption cannot disagree. */
        get vectorScale() {
            return VECTOR_SCALE;
        },

        /** Does the open station carry prisms at all? */
        get hasStakes() {
            return (this.$store.viewer.station?.hotspots ?? []).some((item) => item.type === 'plot');
        },

        /* -------------------------------------------------------------- *
         |  Placing pins
         * -------------------------------------------------------------- */

        /** The marker currently under the pointer, by plugin id. */
        get dragId() {
            if (this.draggingHotspot) {
                if (this.draggingStake) {
                    return `stake-${this.draggingHotspot}-${this.draggingStake}`;
                }

                /*
                | A section's marker is `section-<id>`, not `hotspot-<id>`.
                | Returning the wrong one left `updateMarker` addressing a
                | marker that does not exist, so nothing followed the pointer
                | and the plate only jumped to its new angles once the drop had
                | written the record — a placement done blind.
                */
                const hotspot = this.hotspotFor(`hotspot-${this.draggingHotspot}`);

                return hotspot?.type === 'piezo'
                    ? `section-${this.draggingHotspot}`
                    : `hotspot-${this.draggingHotspot}`;
            }

            return this.draggingMarker ? `station-${this.draggingMarker}` : null;
        },

        /**
         * The line as it would look with one stake dropped at a point.
         *
         * The offset is measured against where that stake *would* sit with no
         * nudge at all, so dragging the line afterwards carries the correction
         * with it instead of leaving it behind at an absolute angle.
         */
        nudgedPlot(hotspot, index, yaw, pitch) {
            const places = hotspot.meta?.places ?? {};
            const withPlaces = (own) => ({
                ...hotspot,
                meta: { ...(hotspot.meta ?? {}), places: { ...places, [index]: own } },
            });

            const base = plotPoints(withPlaces([0, 0]), hotspot.yaw, hotspot.pitch)[index - 1];
            const nudge = [
                Number((yaw - base.yaw).toFixed(3)),
                Number((pitch - base.pitch).toFixed(3)),
            ];

            return { nudge, hotspot: withPlaces(nudge) };
        },

        /**
         * Dragging happens on the container rather than through the markers
         * plugin: a pin has to keep following the pointer even when it leaves
         * its own 30px element.
         *
         * One handle serves both views. The base panorama places station pins;
         * inside a station it places that station's own hotspots, which is how
         * a monitoring plot ends up on the slope it actually covers — the
         * renders are not surveyed, so the seeded angles are a starting point,
         * not truth.
         */
        bindDragging() {
            const sphere = this.$refs.sphere;

            sphere.addEventListener('pointerdown', (event) => {
                this.clearHighlight();

                if (!this.editMarkers) {
                    return;
                }

                const handle = this.stationView
                    ? event.target.closest?.('[data-hotspot]')
                    : event.target.closest?.('[data-station]');

                if (!handle) {
                    return;
                }

                event.stopPropagation();

                if (this.stationView) {
                    this.draggingHotspot = Number(handle.dataset.hotspot);
                    // A caption moves the whole line; a stake moves only itself.
                    this.draggingStake = handle.dataset.stake ? Number(handle.dataset.stake) : null;
                } else {
                    this.draggingMarker = handle.dataset.station;
                }

                // Where the grab started, so a click can be told from a drag.
                psv.grabbedAt = { x: event.clientX, y: event.clientY };

                // Stop the sphere from panning under the marker being placed.
                psv.viewer?.setOption('mousemove', false);
                capture(sphere, 'setPointerCapture', event.pointerId);
            });

            sphere.addEventListener('pointermove', (event) => {
                const id = this.dragId;

                if (!id) {
                    return;
                }

                if (!moved(psv.grabbedAt, event)) {
                    return;
                }

                const position = this.pointToSphere(event);

                if (!position) {
                    return;
                }

                const plot = this.draggingHotspot ? this.hotspotFor(id) : null;

                // A line is several markers around one centre, so its petak
                // and stakes have to follow whatever is being dragged: the
                // caption moves the centre, a stake moves its own offset.
                if (plot?.type === 'plot') {
                    const shown = this.draggingStake
                        ? this.nudgedPlot(plot, this.draggingStake, position.yaw / DEG, position.pitch / DEG).hotspot
                        : plot;
                    const centre = this.draggingStake
                        ? [plot.yaw, plot.pitch]
                        : [position.yaw / DEG, position.pitch / DEG];

                    const grid = this.plotGrid({ hotspot: shown, yaw: centre[0], pitch: centre[1] });

                    // Every line is redrawn, not just the one under the
                    // pointer: moving a prism changes how much room the petak
                    // beside it have, whichever line they belong to.
                    (this.$store.viewer.station?.hotspots ?? [])
                        .filter((item) => item.type === 'plot')
                        .forEach((item) => {
                            this.plotMarkers(item.id === shown.id ? shown : item, grid.get(item.id))
                                .forEach((marker) => psv.markers?.updateMarker(marker));
                        });

                    return;
                }

                psv.markers?.updateMarker({ id, position });
            });

            const drop = (event) => {
                const code = this.draggingMarker;
                const hotspot = this.draggingHotspot;
                const stake = this.draggingStake;

                if (!code && !hotspot) {
                    return;
                }

                const position = this.pointToSphere(event);
                const dragged = moved(psv.grabbedAt, event);

                this.draggingMarker = null;
                this.draggingHotspot = null;
                this.draggingStake = null;
                psv.viewer?.setOption('mousemove', true);
                capture(sphere, 'releasePointerCapture', event.pointerId);

                /*
                | A click is not a placement. Without this, tapping a marker
                | while placement is on rewrites its angles to wherever the
                | pointer happened to be — a silent move of survey data that
                | nobody asked for.
                */
                if (!position || !dragged) {
                    return;
                }

                const yaw = wrapYaw(position.yaw / DEG);
                const pitch = position.pitch / DEG;

                if (hotspot && stake) {
                    this.placeStake(hotspot, stake, yaw, pitch);

                    return;
                }

                if (hotspot) {
                    this.placeHotspot(hotspot, yaw, pitch);

                    return;
                }

                const marker = this.$store.site.markerByCode(code);

                if (marker) {
                    marker.sphere = { yaw, pitch, placed: true };
                }

                postJson(`/api/stations/${code}/sphere`, { yaw, pitch }).catch(() => {});
            };

            sphere.addEventListener('pointerup', drop);
            sphere.addEventListener('pointercancel', drop);
        },

        /**
         * Write a hotspot's new angles to the payload as well as the record:
         * the next `syncPins()` rebuilds every marker from `station.hotspots`,
         * so a position left only on the server snaps back until the panorama
         * is loaded again.
         */
        placeHotspot(id, yaw, pitch) {
            const hotspot = (this.$store.viewer.station?.hotspots ?? [])
                .find((item) => item.id === id);

            if (hotspot) {
                hotspot.yaw = yaw;
                hotspot.pitch = pitch;
            }

            // Neighbouring petak may have room they did not have before.
            this.syncPins();

            postJson(`/api/hotspots/${id}/position`, { yaw, pitch }).catch(() => {});
        },

        /**
         * Nudge one stake off the line's own layout.
         *
         * What is stored is the offset, not the angle: the line keeps its
         * shape, and moving the line later carries every correction with it.
         */
        placeStake(id, stake, yaw, pitch) {
            const hotspot = (this.$store.viewer.station?.hotspots ?? [])
                .find((item) => item.id === id);

            if (!hotspot) {
                return;
            }

            const { nudge } = this.nudgedPlot(hotspot, stake, yaw, pitch);

            hotspot.meta = {
                ...(hotspot.meta ?? {}),
                places: { ...(hotspot.meta?.places ?? {}), [stake]: nudge },
            };

            this.syncPins();

            postJson(`/api/hotspots/${id}/stakes/${stake}`, {
                offset_yaw: nudge[0],
                offset_pitch: nudge[1],
            }).catch(() => {});
        },

        /** Screen point -> spherical coordinates, in radians. */
        pointToSphere(event) {
            if (!psv.viewer) {
                return null;
            }

            const rect = this.$refs.sphere.getBoundingClientRect();

            return psv.viewer.dataHelper.viewerCoordsToSphericalCoords({
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            });
        },

        toggleMarkerEditing() {
            this.editMarkers = !this.editMarkers;
            this.draggingMarker = null;
            this.draggingHotspot = null;
            this.draggingStake = null;
        },

        toggleLabels() {
            this.showLabels = !this.showLabels;

            try {
                window.localStorage.setItem('twin.labels', this.showLabels ? '1' : '0');
            } catch {
                // Private mode: the toggle works, it just does not persist.
            }
        },

        /* -------------------------------------------------------------- *
         |  Camera
         * -------------------------------------------------------------- */

        focusMarker(marker) {
            if (!psv.viewer) {
                return;
            }

            const sphere = this.$store.site.markerByCode(marker.code)?.sphere ?? marker.sphere;

            this.highlight(marker.code);
            this.setRotating(false);
            psv.flight = settle(psv.viewer.animate({
                yaw: (sphere?.yaw ?? 0) * DEG,
                pitch: (sphere?.pitch ?? 0) * DEG,
                zoom: this.closerZoom(18),
                speed: '6rpm',
            }));
        },

        /** Turn the camera until the compass reads north. */
        faceNorth() {
            this.setRotating(false);
            this.lookAt(-this.northOffset, 0);
        },

        /**
         * Name a pin and keep it named. No timer: the search result should
         * still be identifiable after the camera finishes turning, however long
         * the reader takes to look. Touching the sphere clears it.
         */
        highlight(code) {
            this.highlighted = code;
            this.syncPins();
        },

        clearHighlight() {
            if (this.highlighted) {
                this.highlighted = null;
                this.syncPins();
            }
        },

        lookAt(yaw, pitch = 0) {
            settle(psv.viewer?.animate({ yaw: yaw * DEG, pitch: pitch * DEG, speed: '3rpm' }));
        },

        /**
         * Keep dragging roughly one-to-one with the pointer.
         *
         * The viewer turns a drag into an angle using the *vertical* field of
         * view over the container height, so a tall, narrow stage (a phone, or
         * a slim window) drags far slower than the picture under the cursor.
         * Correcting by the aspect ratio lands on the viewer's own default for
         * a 16:9 stage and speeds the narrow ones up.
         */
        tuneMoveSpeed() {
            if (!psv.viewer) {
                return;
            }

            const { width, height } = psv.viewer.getSize();

            if (!width || !height) {
                return;
            }

            psv.viewer.setOption('moveSpeed', Math.min(2.6, Math.max(0.9, (1.7 * height) / width)));
        },

        /** Zoom buttons glide as well; a jump reads as a stutter. */
        zoomBy(delta) {
            if (!psv.viewer) {
                return;
            }

            settle(psv.viewer.animate({
                zoom: Math.min(100, Math.max(0, psv.viewer.getZoomLevel() + delta)),
                duration: 320,
            }));
        },

        zoomIn() {
            this.zoomBy(12);
        },

        zoomOut() {
            this.zoomBy(-12);
        },

        reset() {
            const panorama = psv.showing?.panorama;

            const stage = this.$store.site.environment?.stage;
            const isBase = psv.showing?.code === this.base?.code;

            settle(psv.viewer?.animate({
                yaw: this.openingYaw(panorama, isBase) * DEG,
                pitch: (panorama?.pitch || (isBase ? stage?.default_pitch : 0) || 0) * DEG,
                zoom: this.restingZoom(),
                speed: '4rpm',
            }));
        },

        setRotating(value) {
            this.rotating = value;

            if (!psv.autorotate) {
                return;
            }

            // Turning it off has to stop the idle restart as well, otherwise the
            // drift creeps back a few seconds later. It is a plugin option, not
            // a viewer one.
            psv.autorotate.setOption('autostartOnIdle', value);

            value ? psv.autorotate.start() : psv.autorotate.stop();
        },

        toggleRotate() {
            this.setRotating(!this.rotating);
        },

        fullscreen() {
            psv.viewer?.toggleFullscreen();
        },

        destroySphere() {
            window.clearInterval(psv.captions);
            (psv.turns ?? []).splice(0).forEach(window.clearTimeout);
            psv.captions = null;
            psv.viewer?.destroy();
            psv.viewer = null;
            psv.markers = null;
            psv.autorotate = null;
            psv.showing = null;
            this.ready = false;
        },
    };
}

/** Plain delay, used to hand the movement over before an animation ends. */
function wait(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

/** Wait for a camera move without caring whether it finished or was cut off. */
function settle(animation) {
    return Promise.resolve(animation).catch(() => {});
}

/** Pointer capture is best effort: the pointer may already be gone. */
function capture(element, method, pointerId) {
    try {
        element[method](pointerId);
    } catch {
        // No active pointer with that id — nothing to hold on to.
    }
}

/** One station pin: status-coloured dot plus its caption. */
/** Pointer travel, in CSS pixels, that separates a drag from a click. */
const DRAG_SLOP = 4;

function moved(from, event) {
    if (!from) {
        return false;
    }

    return Math.hypot(event.clientX - from.x, event.clientY - from.y) >= DRAG_SLOP;
}

/** How a line of petak is shaped when its record does not say. */
const PLOT = { stakes: 5, gap: 3.6, cellYaw: 2.4, cellPitch: 1.7, line: 0, foreshorten: 1 };

/**
 * One stake's petak: a parallelogram lying on the slope, around its centre.
 *
 * Not a rectangle. The slope is an oblique plane seen in perspective, so the
 * grid it carries — the rows of prisms one way, the fall from one row to the
 * next the other — does not meet at a right angle in the picture. On this dam
 * the two run 102 degrees apart. Squaring the second edge off the first is
 * what turned the petak into diamonds standing on the rip-rap instead of
 * patches lying in it.
 *
 * `step` is the direction along the line and `fall` the direction to the next
 * line down the slope, both unit vectors in the picture's own frame (yaw to
 * the right, pitch upwards). Both are measured from where the prisms actually
 * are, so the grid is the reader's placement rather than the record's plan.
 */
function petak(yaw, pitch, step, fall) {
    return [[1, 1], [1, -1], [-1, -1], [-1, 1]].map(([a, b]) => [
        yaw + (a * step[0] + b * fall[0]) / 2,
        pitch + (a * step[1] + b * fall[1]) / 2,
    ]);
}

/** A unit vector, or null when there is no direction to be had. */
function unit(vector) {
    const length = Math.hypot(vector[0], vector[1]);

    return length > 1e-6 ? [vector[0] / length, vector[1] / length] : null;
}

/** Turned a quarter clockwise, which is all a separating axis needs. */
function perp(vector) {
    return [-vector[1], vector[0]];
}

/**
 * Which way the line runs at one of its stakes, in radians.
 *
 * Taken from the prisms either side of it rather than from `meta.line`: once a
 * line has been placed by hand it no longer runs the way the record laid it
 * out, and the petak have to follow the placement, not the plan. `meta.line`
 * is the fallback for a line of one.
 */
function stakeAim(points, index, line) {
    const from = points[index - 1] ?? points[index];
    const to = points[index + 1] ?? points[index];

    return unit([to.yaw - from.yaw, to.pitch - from.pitch]) ?? [Math.cos(line), -Math.sin(line)];
}

/**
 * Where a line's stakes stand, in degrees, nudges included.
 *
 * A survey line does not run across the picture — it runs where the dam runs.
 * `meta.line` is the direction of the line in the image (0 = right, 90 = down)
 * so five stakes can march away along the crest instead of sideways across it,
 * and `meta.foreshorten` crowds each step, which is what makes a receding line
 * read as lying on the slope rather than painted flat over it.
 *
 * Everything is derived from the line's own centre. `meta.places` is the
 * exception, and the only one: a stake nudged onto the spot it really occupies
 * keeps an offset there, so the line can be placed as a piece and then
 * corrected stake by stake without turning five prisms into five records.
 */
function plotPoints(hotspot, yaw, pitch) {
    const meta = hotspot.meta ?? {};
    const count = Math.max(1, Math.round(Number(meta.stakes ?? PLOT.stakes)));
    const gap = Number(meta.stake_gap ?? PLOT.gap);
    const line = Number(meta.line ?? PLOT.line) * DEG;
    const shrink = Number(meta.foreshorten ?? PLOT.foreshorten);
    const places = meta.places ?? {};

    // How far along the line each stake stands.
    const steps = [];
    let run = 0;

    for (let index = 0; index < count; index += 1) {
        steps.push(run);
        run += gap * shrink ** index;
    }

    const middle = steps[count - 1] / 2;

    const points = steps.map((step, index) => {
        const along = step - middle;
        const nudge = places[index + 1] ?? places[String(index + 1)] ?? [0, 0];

        return {
            yaw: yaw + Math.cos(line) * along + Number(nudge[0] ?? 0),
            pitch: pitch - Math.sin(line) * along + Number(nudge[1] ?? 0),
            moved: Number(nudge[0] ?? 0) !== 0 || Number(nudge[1] ?? 0) !== 0,
        };
    });

    return points.map((point, index) => ({ ...point, aim: stakeAim(points, index, line) }));
}

/**
 * The paint a prism gets before anything has been measured at it.
 *
 * White with the dark rim the markers already carry — the same plain shape
 * the petak wore before status colour arrived, which is what "not read" ought
 * to look like next to four colours that all mean "read".
 */
const UNREAD = '#e8f4ff';

/**
 * How much of the ground between two prisms a petak takes.
 *
 * A petak fills the space between the stakes rather than floating in it — the
 * slope belongs to one prism or the next — so what is left is only the sliver
 * that keeps two dashed outlines apart.
 */
const PETAK_FILL = 0.88;

/**
 * How much two petak may keep before they reach each other.
 *
 * One means they only touch at full size; below one, both have to come down to
 * that fraction. This is the separating-axis test solved for the scale rather
 * than answered yes or no: on each of the four edge normals the two are clear
 * when the distance between their centres covers both their reaches, and the
 * axis that gives them the most room is the one that decides it.
 *
 * It has to be a *pair* test. Sizing each petak against a neighbour on its own
 * — as if the neighbour were the same size — holds only while every petak is
 * the same size, and they stopped being that the moment each one took its
 * measurements from its own patch of ground.
 */
function petakRoom(a, b) {
    const dy = b.yaw - a.yaw;
    const dp = b.pitch - a.pitch;
    const edges = [a.step, a.fall, b.step, b.fall];
    let best = 0;

    for (const edge of edges) {
        const axis = unit(perp(edge));

        if (!axis) {
            continue;
        }

        const reach = edges.reduce(
            (total, side) => total + Math.abs(side[0] * axis[0] + side[1] * axis[1]),
            0,
        ) / 2;

        if (reach > 1e-9) {
            best = Math.max(best, Math.abs(dy * axis[0] + dp * axis[1]) / reach);
        }
    }

    return best;
}

/**
 * Every prism on a station with the ground it stands on.
 *
 * Built for the whole station at once, because none of it is a property of one
 * line: a petak's two edges are the grid the *placement* makes — along the line
 * from the prism beside it, down the slope from the line below — and what
 * finally limits a petak is the nearest prism anywhere, which is often on
 * another line. Three lines step down one slope, and once they have been
 * placed by hand two stakes from different lines can end up a degree apart.
 *
 * `swap` replaces one line with the shape a drag is currently showing, so the
 * grid follows the pointer instead of the record.
 *
 * @returns {Map<number, Array>} stakes per plot id, in stake order
 */
function plotGrid(plots, swap = null) {
    const counted = new Map();
    const place = new Map();

    // Which face a line is on and how far down it. Catalogue order is
    // crest-outwards, which is the order the seeder writes.
    plots.forEach((plot) => {
        const side = plot.meta?.side ?? '';
        const rank = counted.get(side) ?? 0;

        counted.set(side, rank + 1);
        place.set(plot.id, { side, rank });
    });

    // 1. Where every prism stands.
    const lines = plots.map((plot) => {
        const dragged = swap?.hotspot?.id === plot.id;
        const shown = dragged ? swap.hotspot : plot;

        return {
            plot,
            meta: shown.meta ?? {},
            points: plotPoints(shown, dragged ? swap.yaw : plot.yaw, dragged ? swap.pitch : plot.pitch),
            ...place.get(plot.id),
        };
    });

    const at = (side, rank, index) => lines
        .find((line) => line.side === side && line.rank === rank)?.points[index] ?? null;

    // 2. The ground each one covers, measured in the grid's own two directions.
    const cells = [];

    lines.forEach((line) => {
        const across = Number(line.meta.cell_yaw ?? PLOT.cellYaw);
        const along = Number(line.meta.cell_pitch ?? PLOT.cellPitch);

        line.points.forEach((point, index) => {
            const here = at(line.side, line.rank, index);
            const next = at(line.side, line.rank + 1, index);
            const back = at(line.side, line.rank - 1, index);
            const span = (from, to) => {
                const vector = [to.yaw - from.yaw, to.pitch - from.pitch];
                const direction = unit(vector);

                return direction ? { direction, distance: Math.hypot(vector[0], vector[1]) } : null;
            };
            const drop = next ? span(here, next) : (back ? span(back, here) : null);
            const step = point.aim;
            const fall = drop?.direction ?? unit(perp(step)) ?? [0, 1];

            /*
            | Along the line, the prism beside it; down the slope, the line
            | below. One measurement for both edges would size the whole petak
            | off whichever direction happened to be tighter, which is what
            | drew slivers where the lines are far apart and the stakes are
            | not. With nothing to measure — a lone line, a lone stake — the
            | record's own proportions stand in.
            */
            const sides = [line.points[index - 1], line.points[index + 1]]
                .filter(Boolean)
                .map((other) => Math.hypot(other.yaw - point.yaw, other.pitch - point.pitch));
            const row = sides.length ? Math.min(...sides) : Number(line.meta.stake_gap ?? PLOT.gap);
            const reach = PETAK_FILL * row;
            const fallReach = PETAK_FILL * (drop?.distance ?? (row * across) / along);

            cells.push({
                id: line.plot.id,
                index,
                yaw: point.yaw,
                pitch: point.pitch,
                moved: point.moved,
                // Full edge vectors, which is what the pair test works on.
                step: [step[0] * reach, step[1] * reach],
                fall: [fall[0] * fallReach, fall[1] * fallReach],
            });
        });
    });

    /*
    | 3. Nothing may reach anything else. Both ends of a pair are held to the
    |    same fraction, so the pair is clear whichever of them was the reason
    |    — and to a hair under it, because the fraction the test returns is
    |    the one where the two exactly touch, and two petak drawn edge to edge
    |    read as one shape.
    */
    const keep = cells.map(() => 1);

    for (let a = 0; a < cells.length; a += 1) {
        for (let b = a + 1; b < cells.length; b += 1) {
            const room = petakRoom(cells[a], cells[b]) * 0.97;

            if (room < 1) {
                keep[a] = Math.min(keep[a], room);
                keep[b] = Math.min(keep[b], room);
            }
        }
    }

    const grid = new Map();

    cells.forEach((cell, order) => {
        // A prism dropped on top of another still draws something.
        const scale = Math.max(0.15, keep[order]);
        const step = [cell.step[0] * scale, cell.step[1] * scale];
        const fall = [cell.fall[0] * scale, cell.fall[1] * scale];
        const stakes = grid.get(cell.id) ?? [];

        stakes[cell.index] = {
            yaw: cell.yaw,
            pitch: cell.pitch,
            placed: cell.moved,
            cell: petak(cell.yaw, cell.pitch, step, fall),
        };

        grid.set(cell.id, stakes);
    });

    return grid;
}

/** Where a line's caption hangs: above the highest corner it has. */
function plotCaption(stakes) {
    return {
        yaw: stakes.reduce((total, stake) => total + stake.yaw, 0) / stakes.length,
        pitch: Math.max(...stakes.flatMap((stake) => stake.cell.map(([, corner]) => corner))),
    };
}

/**
 * Fold a yaw into -180..180.
 *
 * The server stores it that way, so normalising here keeps the payload the
 * browser holds identical to the record — otherwise a pin dropped past north
 * reads 339 in one place and -21 in the other.
 */
function wrapYaw(yaw) {
    return ((((yaw + 180) % 360) + 360) % 360) - 180;
}

/**
 * Which reading a pin is showing at this turn of the cycle.
 *
 * Every parameter in turn, headline first, so a pin is not a single figure
 * with nine more hidden behind a click. A station whose payload predates this
 * — or one with nothing to report — keeps its old single caption.
 */
function pinReading(marker, step) {
    const readings = marker.readings ?? [];

    if (!readings.length) {
        return { label: marker.type_label ?? '', value: marker.caption ?? '', status: marker.status };
    }

    return readings[((step % readings.length) + readings.length) % readings.length];
}

function pinHtml(marker, color, target = false, highlight = false, step = 0) {
    const state = `${target ? ' is-target' : ''}${highlight ? ' is-highlight' : ''}`;
    const reading = pinReading(marker, step);
    // The figure wears the status of *that* parameter; the dot keeps the
    // station's, which is the worst of them and the reason to look at all.
    const tint = statusColor(reading.status ?? marker.status);

    return `
        <div class="sphere-pin${state}" data-station="${marker.code}">
            <span class="sphere-pin__dot" style="--pin:${color}">${iconSvg(marker.type, 16)}</span>
            <span class="sphere-pin__label">
                <span class="sphere-pin__name">${marker.short_name ?? marker.name}</span>
                <span class="sphere-pin__value tnum" style="color:${tint}">${reading.value ?? ''}</span>
                <span class="sphere-pin__param">${reading.label ?? ''}</span>
            </span>
        </div>
    `;
}

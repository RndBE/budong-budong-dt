import { postJson } from '../lib/api.js';
import { statusColor } from '../lib/format.js';
import { iconSvg } from '../lib/icons.js';
import { hotspotHtml, loadPsv } from './panorama.js';

const DEG = Math.PI / 180;
const PHASES = ['night', 'dawn', 'day', 'dusk'];

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

        initSphere() {
            try {
                this.showLabels = window.localStorage.getItem('twin.labels') !== '0';
            } catch {
                this.showLabels = true;
            }

            this.$watch('$store.site.environment', () => this.mount());
            this.$watch('$store.site.markers', () => this.syncPins());
            this.$watch('sunPhase', () => this.applyPhase());
            this.$watch('$store.viewer.station', (station) => {
                if (station?.panorama?.url) {
                    this.show(station);
                }
            });
            this.$watch('$store.viewer.open', (open) => {
                if (!open) {
                    this.show(this.base);
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
         * The base panorama opens on a compass bearing
         * (`dam.stage.default_bearing`) rather than a raw yaw, so the framing
         * stays put when `north_offset` is corrected: yaw is measured from the
         * picture, the bearing from north. A station opens on its own yaw.
         */
        openingYaw(panorama, isBase) {
            const bearing = this.$store.site.environment?.stage?.default_bearing;

            if (isBase && bearing !== null && bearing !== undefined) {
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

        /** Texture set for one phase, falling back to daylight. */
        phaseAsset(phase) {
            return this.base?.phases?.[phase] ?? this.base?.panorama ?? null;
        },

        /**
         * Swap the base sphere for the texture of the current phase. Scrubbing
         * the clock crosses several phases quickly, so the swap is debounced
         * and skipped whenever a station panorama is on screen.
         */
        applyPhase() {
            window.clearTimeout(psv.phaseTimer);

            psv.phaseTimer = window.setTimeout(() => {
                const phase = this.sunPhase;

                if (!psv.viewer || !this.ready || this.stationView || this.flying) {
                    return;
                }

                if (psv.showing?.code !== this.base?.code || phase === this.phase) {
                    return;
                }

                const asset = this.phaseAsset(phase);

                if (!asset?.url) {
                    return;
                }

                this.phase = phase;

                psv.viewer
                    .setPanorama(asset.preview ?? asset.url, {
                        showLoader: false,
                        // Slow on the real clock — the light of the valley
                        // should change the way it does outside. Scrubbing runs
                        // through whole phases, so it gets a quicker fade.
                        transition: {
                            effect: 'fade',
                            rotation: false,
                            speed: this.$store.site.time.mode === 'custom' ? 900 : 2600,
                        },
                    })
                    .then(() => psv.viewer.setPanorama(asset.url, { showLoader: false, transition: false }))
                    .catch(() => {});
            }, 400);
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

            const base = this.base;
            psv.showing = base;
            this.phase = this.sunPhase;
            this.setNorthOffset(base.panorama.north_offset ?? 0);

            const opening = this.phaseAsset(this.phase);

            psv.viewer = new Viewer({
                container: this.$refs.sphere,
                panorama: opening.preview ?? opening.url,
                caption: base.name,
                navbar: false,
                defaultYaw: this.openingYaw(base.panorama, true) * DEG,
                defaultPitch: (base.panorama.pitch || this.$store.site.environment?.stage?.default_pitch || 0) * DEG,
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
                this.zoom = psv.viewer.getZoomLevel() / 100;

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
            this.setNorthOffset(target.panorama?.north_offset ?? 0);

            if (same) {
                this.syncPins();

                return;
            }

            // The base sphere has one texture per time of day.
            const isBase = target.code === this.base?.code;
            const asset = isBase ? this.phaseAsset(this.sunPhase) : target.panorama;

            if (isBase) {
                this.phase = this.sunPhase;
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
            // the pin, and asking it to swing to the new panorama's default yaw
            // mid-fade is the jump that reads as a blink. Only the lean-in eases
            // back out.
            const resting = this.restingZoom();

            // Arriving is a step *into* the place: the picture cross-fades at
            // the framing the approach ended on, then the new scene opens up
            // towards the camera. Nothing ever pulls back on the way in — that
            // reversal is what made the move feel like walking out.
            const swap = psv.viewer
                .setPanorama(preview, {
                    caption: target.name,
                    showLoader: false,
                    transition: { effect: 'fade', rotation: false, speed: 1000 },
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
                ? this.phaseAsset(this.phase ?? this.sunPhase)
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

            // While the camera is still turning, the pins stay: the one being
            // approached is what the movement is pointing at. They come down
            // when the cross-fade starts.
            const showHotspots = this.stationView && !this.pointing;

            psv.markers.setMarkers(showHotspots ? this.hotspotMarkers() : this.stationMarkers());
        },

        /** Station types the "Ukuran Air" filter keeps. */
        get waterTypes() {
            return ['water_level', 'water_quality', 'seepage', 'gate', 'piezometer', 'observation_well'];
        },

        setPinFilter(mode) {
            this.pinFilter = mode;
            this.syncPins();
        },

        stationMarkers() {
            const baseCode = this.base?.code;
            const water = this.waterTypes;

            return this.$store.site.markers
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
                        ),
                        anchor: 'center center',
                        zIndex: marker.code === this.$store.viewer.code ? 60 : 40,
                        data: { code: marker.code },
                    };
                });
        },

        hotspotMarkers() {
            const station = this.$store.viewer.station;
            const metrics = new Map((station?.metrics ?? []).map((metric) => [metric.key, metric]));

            return (station?.hotspots ?? []).map((hotspot) => {
                const metric = hotspot.metric_key ? metrics.get(hotspot.metric_key) : null;
                const color = metric ? statusColor(metric.status) : '#47a6ff';
                const value = metric ? `${metric.formatted} ${metric.unit ?? ''}`.trim() : null;

                return {
                    id: `hotspot-${hotspot.id}`,
                    position: { yaw: hotspot.yaw * DEG, pitch: hotspot.pitch * DEG },
                    html: hotspotHtml(hotspot, value, color),
                    anchor: 'bottom center',
                    ...(hotspot.description
                        ? { tooltip: { content: hotspot.description, position: 'top center' } }
                        : {}),
                    data: hotspot,
                };
            });
        },

        onMarker(id) {
            if (this.draggingMarker) {
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

            const hotspot = (this.$store.viewer.station?.hotspots ?? [])
                .find((item) => item.id === Number(String(id).replace('hotspot-', '')));

            if (!hotspot) {
                return;
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

        /* -------------------------------------------------------------- *
         |  Placing pins
         * -------------------------------------------------------------- */

        /**
         * Dragging happens on the container rather than through the markers
         * plugin: a pin has to keep following the pointer even when it leaves
         * its own 30px element.
         */
        bindDragging() {
            const sphere = this.$refs.sphere;

            sphere.addEventListener('pointerdown', (event) => {
                this.clearHighlight();

                const pin = event.target.closest?.('[data-station]');

                if (!pin || !this.editMarkers || this.stationView) {
                    return;
                }

                event.stopPropagation();
                this.draggingMarker = pin.dataset.station;
                // Stop the sphere from panning under the pin being placed.
                psv.viewer?.setOption('mousemove', false);
                capture(sphere, 'setPointerCapture', event.pointerId);
            });

            sphere.addEventListener('pointermove', (event) => {
                if (!this.draggingMarker) {
                    return;
                }

                const position = this.pointToSphere(event);

                if (position) {
                    psv.markers?.updateMarker({ id: `station-${this.draggingMarker}`, position });
                }
            });

            const drop = (event) => {
                if (!this.draggingMarker) {
                    return;
                }

                const code = this.draggingMarker;
                const position = this.pointToSphere(event);

                this.draggingMarker = null;
                psv.viewer?.setOption('mousemove', true);
                capture(sphere, 'releasePointerCapture', event.pointerId);

                if (!position) {
                    return;
                }

                const yaw = position.yaw / DEG;
                const pitch = position.pitch / DEG;

                const marker = this.$store.site.markerByCode(code);

                if (marker) {
                    marker.sphere = { yaw, pitch, placed: true };
                }

                postJson(`/api/stations/${code}/sphere`, { yaw, pitch }).catch(() => {});
            };

            sphere.addEventListener('pointerup', drop);
            sphere.addEventListener('pointercancel', drop);
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
function pinHtml(marker, color, target = false, highlight = false) {
    const state = `${target ? ' is-target' : ''}${highlight ? ' is-highlight' : ''}`;

    return `
        <div class="sphere-pin${state}" data-station="${marker.code}">
            <span class="sphere-pin__dot" style="--pin:${color}">${iconSvg(marker.type, 13)}</span>
            <span class="sphere-pin__label">
                <span class="sphere-pin__name">${marker.short_name ?? marker.name}</span>
                <span class="sphere-pin__value tnum" style="color:${color}">${marker.caption ?? ''}</span>
            </span>
        </div>
    `;
}

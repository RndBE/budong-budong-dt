import Alpine from 'alpinejs';
import { getJson, poll, postJson } from './lib/api.js';
import { formatClock, formatSiteDate, relativeTime, siteNow, statusColor } from './lib/format.js';
import { disposeChart, resizeCharts, seriesChart, sparkline } from './lib/charts.js';
import { iconSvg } from './lib/icons.js';
import mapStage from './components/map-stage.js';
import twinSphere from './components/twin-sphere.js';

/* ------------------------------------------------------------------ *
 |  Stores
 * ------------------------------------------------------------------ */

const PHASES = ['night', 'dawn', 'day', 'dusk'];

/**
 * Run `task` now, or when a prerendered document is activated.
 *
 * Chromium can build a page in the background from a speculation rule; while
 * it does, `document.prerendering` is true and the reader has not seen it.
 */
function whenPageIsActive(task) {
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', task, { once: true });

        return;
    }

    task();
}

/** Turn a {primary, secondary, mix} scene into per-still opacities. */
function phaseWeights(scene) {
    const weights = { night: 0, dawn: 0, day: 0, dusk: 0 };
    const mix = Math.max(0, Math.min(1, scene.mix ?? 0));

    weights[scene.primary] = (weights[scene.primary] ?? 0) + (1 - mix);
    weights[scene.secondary] = (weights[scene.secondary] ?? 0) + mix;

    return weights;
}

Alpine.store('site', {
    boot: null,
    environment: null,
    markers: [],
    dashboard: null,
    clock: { time: '00:00:00', date: '', zone: 'WITA', simulated: false },
    skin: 'auto',
    skewMs: 0,
    stopPolling: [],

    /*
     * Time control for the digital twin stage. `realtime` follows the site
     * clock; `custom` drives the lighting from a sampled day curve, which can
     * be scrubbed or played back faster than real time.
     */
    time: {
        mode: 'realtime',
        minute: 0,
        speed: 5,         // simulated minutes per real second while playing
        playing: false,
        curve: null,
    },
    lastTickAt: 0,

    /** Messages from the service desk nobody has opened yet. */
    maintenanceUnread: 0,
    abilities: [],

    /** Ability codes from config/access.php, as granted to the signed-in role. */
    can(ability) {
        return this.abilities.includes(ability);
    },

    start(boot, dashboard = null, live = true) {
        this.boot = boot;
        this.environment = boot.environment;
        this.markers = boot.markers ?? [];
        this.dashboard = dashboard;
        this.skin = boot.skin ?? 'auto';
        this.maintenanceUnread = boot.maintenance_unread ?? 0;
        this.abilities = boot.can ?? [];

        // Trust the server clock: the browser's own may be off by minutes.
        this.skewMs = new Date(boot.environment.clock.iso).getTime() - Date.now();

        this.tick();
        window.setInterval(() => this.tick(), 1000);

        /*
         * Simulated time advances per animation frame while the tab is visible,
         * with a slow interval as a backstop for when the browser throttles
         * frames. Both call the same accumulator, so the rate stays exactly
         * what the speed button says no matter which one fires.
         */
        const frame = () => {
            this.advance();
            window.requestAnimationFrame(frame);
        };
        window.requestAnimationFrame(frame);
        window.setInterval(() => this.advance(), 250);

        /*
        | A prerendered page is a real document that nobody has opened yet:
        | polling from it would spend requests — and, on a dev server that
        | answers one at a time, the reader's own page — on a guess. Wait for
        | the activation.
        */
        whenPageIsActive(() => this.startPolling(boot, dashboard, live));
    },

    /** @internal Called once the document is the one being looked at. */
    startPolling(boot, dashboard, live) {
        this.stopPolling.push(poll(() => this.refreshEnvironment(), boot.refresh.environment));

        /*
        | A page with no summary panel shows dashboard numbers only in the
        | system monitor, so it keeps the poll but at a quarter of the rate:
        | four fewer round trips a minute on every screen that is not reading
        | them. `live` comes from the layout.
        */
        this.stopPolling.push(poll(
            () => this.refreshDashboard(),
            boot.refresh.dashboard * (live ? 1 : 4),
        ));

        /*
        | The markers were inlined in the boot payload a moment ago, so the
        | first refresh only wants the numbers. Asking for both meant two more
        | round trips before the page had finished settling.
        */
        if (!dashboard) {
            this.refreshDashboard(false).catch(() => {});
        }
    },

    /**
     * Stop the background polls.
     *
     * Called when a navigation starts: the page is on its way out, and a dev
     * server that handles one request at a time would otherwise make the next
     * page's HTML queue behind a refresh nobody will read.
     */
    pausePolling() {
        this.stopPolling.forEach((stop) => stop());
        this.stopPolling = [];
    },

    /** Move simulated time forward; called once per animation frame. */
    advance() {
        const stamp = Date.now();
        // Clamp: a backgrounded tab must not fast-forward hours on return.
        const elapsed = this.lastTickAt ? Math.min(1, (stamp - this.lastTickAt) / 1000) : 0;
        this.lastTickAt = stamp;

        if (this.time.mode !== 'custom' || !this.time.playing || elapsed <= 0) {
            return;
        }

        this.time.minute = (this.time.minute + this.time.speed * elapsed) % 1440;
        this.tick();
    },

    tick() {
        const offset = this.environment?.offset_minutes ?? 480;
        const now = siteNow(offset, this.skewMs);
        const zone = this.environment?.clock?.zone_label ?? 'WITA';

        if (this.time.mode === 'custom') {
            const minute = Math.floor(this.time.minute);
            const pad = (value) => String(value).padStart(2, '0');

            this.clock = {
                time: `${pad(Math.floor(minute / 60))}:${pad(minute % 60)}:00`,
                date: formatSiteDate(now),
                zone,
                simulated: true,
            };

            return;
        }

        this.clock = {
            time: formatClock(now),
            date: formatSiteDate(now),
            zone,
            simulated: false,
        };
    },

    /* ------------------------------------------------ time control */

    async loadCurve() {
        if (this.time.curve) {
            return this.time.curve;
        }

        this.time.curve = await getJson('/api/environment/curve', { step: 10 });

        return this.time.curve;
    },

    /** Lighting at a given minute of the day, interpolated between samples. */
    sampleAt(minute) {
        const curve = this.time.curve;

        if (!curve) {
            return null;
        }

        const step = curve.step_minutes;
        const index = Math.min(curve.samples.length - 2, Math.floor(minute / step));
        const from = curve.samples[index];
        const to = curve.samples[index + 1] ?? from;
        const t = Math.min(1, Math.max(0, (minute - from.minute) / step));

        const lerp = (a, b) => a + (b - a) * t;

        // Blend in weight space: two samples that use different stills would
        // otherwise have to swap an image mid-fade, which reads as a blink.
        const fromWeights = phaseWeights(from);
        const toWeights = phaseWeights(to);
        const weights = {};
        PHASES.forEach((phase) => {
            weights[phase] = lerp(fromWeights[phase] ?? 0, toWeights[phase] ?? 0);
        });

        return {
            phase: t < 0.5 ? from.phase : to.phase,
            phase_label: t < 0.5 ? from.phase_label : to.phase_label,
            elevation: lerp(from.elevation, to.elevation),
            primary: t < 0.5 ? from.primary : to.primary,
            secondary: t < 0.5 ? from.secondary : to.secondary,
            mix: t < 0.5 ? from.mix : to.mix,
            weights,
            daylight: lerp(from.daylight, to.daylight),
            grade: {
                brightness: lerp(from.grade.brightness, to.grade.brightness),
                contrast: lerp(from.grade.contrast, to.grade.contrast),
                saturate: lerp(from.grade.saturate, to.grade.saturate),
                warmth: lerp(from.grade.warmth, to.grade.warmth),
            },
        };
    },

    /**
     * Opacity for each of the four stills. The stage keeps all four layers
     * mounted and only animates these numbers, so nothing has to decode a new
     * image while the light changes.
     */
    get phaseWeights() {
        const empty = { night: 0, dawn: 0, day: 0, dusk: 0 };

        if (this.skin !== 'auto') {
            return { ...empty, [this.skin]: 1 };
        }

        const simulated = this.simulated;

        if (simulated?.weights) {
            return { ...empty, ...simulated.weights };
        }

        const scene = this.environment?.scene;

        return scene ? { ...empty, ...phaseWeights(scene) } : { ...empty, day: 1 };
    },

    get simulated() {
        return this.time.mode === 'custom' ? this.sampleAt(this.time.minute) : null;
    },

    async useCustomTime(minute = null) {
        await this.loadCurve();

        if (minute === null) {
            // Start from the current site time so the switch is seamless.
            const now = siteNow(this.environment?.offset_minutes ?? 480, this.skewMs);
            minute = now.getUTCHours() * 60 + now.getUTCMinutes();
        }

        this.time.minute = minute;
        this.time.mode = 'custom';
        this.lastTickAt = Date.now();
    },

    useRealtime() {
        this.time.mode = 'realtime';
        this.time.playing = false;
        this.tick();
    },

    setMinute(minute) {
        this.time.minute = Math.max(0, Math.min(1439, Number(minute)));

        // Touching the slider is the gesture that leaves real time behind.
        if (this.time.mode !== 'custom') {
            this.useCustomTime(this.time.minute);
        }
    },

    setSpeed(speed) {
        this.time.speed = speed;
        this.time.playing = true;
        this.lastTickAt = Date.now();

        if (this.time.mode !== 'custom') {
            this.useCustomTime();
        }
    },

    togglePlay() {
        this.time.playing = !this.time.playing;
        this.lastTickAt = Date.now();

        if (this.time.playing && this.time.mode !== 'custom') {
            this.useCustomTime();
        }
    },

    /** Spells out what the multiplier on the buttons means. */
    get speedLabel() {
        const span = {
            1: '1 menit',
            5: '5 menit',
            15: '15 menit',
            60: '1 jam',
        }[this.time.speed] ?? `${this.time.speed} menit`;

        return `${span} tiap detik nyata`;
    },

    async refreshEnvironment() {
        this.environment = await getJson('/api/environment');
    },

    /**
     * The numbers, and — unless the caller says otherwise — the markers.
     *
     * Both used to be fetched one after the other on every tick. The two calls
     * are independent, so they go together, and the very first refresh after a
     * page load skips the markers it was just handed.
     */
    async refreshDashboard(withMarkers = true) {
        if (!withMarkers) {
            this.dashboard = await getJson('/api/dashboard');

            return;
        }

        const [dashboard, stations] = await Promise.all([
            getJson('/api/dashboard'),
            getJson('/api/stations'),
        ]);

        this.dashboard = dashboard;
        this.markers = stations.data;
    },

    /** Background layers for the current sun phase, or a manual override. */
    get scene() {
        return this.sceneOver(this.environment?.scene?.assets);
    },

    /**
     * The page backdrop: the same phase choice over the base panorama.
     *
     * The preview tier is enough — the layer is blurred and sits under a dark
     * gradient — and it is a fifth of the weight of the orthographic stills.
     */
    get backdrop() {
        const phases = this.environment?.stage?.base?.phases;

        const assets = phases
            ? Object.fromEntries(Object.entries(phases).map(([phase, asset]) => [phase, asset.preview]))
            : null;

        return this.sceneOver(assets ?? this.environment?.scene?.assets);
    },

    /** Pick the two stills and their mix for the current phase. */
    sceneOver(assets) {
        const scene = this.environment?.scene;

        if (!scene || !assets) {
            return { primary: null, secondary: null, mix: 0, grade: {} };
        }

        const simulated = this.simulated;

        if (simulated && this.skin === 'auto') {
            return {
                primary: assets[simulated.primary],
                secondary: assets[simulated.secondary],
                mix: simulated.mix,
                grade: simulated.grade,
                phase: simulated.primary,
                phase_label: simulated.phase_label,
                daylight: simulated.daylight,
            };
        }

        if (this.skin !== 'auto') {
            return {
                primary: assets[this.skin],
                secondary: assets[this.skin],
                mix: 0,
                grade: scene.grade,
                phase: this.skin,
            };
        }

        return {
            primary: assets[scene.primary],
            secondary: assets[scene.secondary],
            mix: scene.mix,
            grade: scene.grade,
            phase: scene.primary,
        };
    },

    /** No CSS easing while scrubbing: the values already move every frame. */
    get sceneTransition() {
        return this.time.mode === 'custom' ? '0ms' : '900ms';
    },

    get stageFilter() {
        const grade = this.scene.grade ?? {};

        return `brightness(${grade.brightness ?? 1}) contrast(${grade.contrast ?? 1}) saturate(${grade.saturate ?? 1})`;
    },

    get warmth() {
        return this.scene.grade?.warmth ?? 0;
    },

    setSkin(skin) {
        this.skin = skin;
        postJson('/api/settings', { preferences: { map_skin: skin } }).catch(() => {});
    },

    markerByCode(code) {
        return this.markers.find((marker) => marker.code === code);
    },

    /**
     * Donut markup for the structural health card. Built as a string because
     * `<template x-for>` is not usable inside an <svg> element.
     */
    get healthDonut() {
        return this.healthArcs
            .map((arc) => `<circle cx="60" cy="60" r="52" fill="none" stroke-width="11" stroke-linecap="round"
                    stroke="${arc.color}" stroke-dasharray="${arc.dash}" stroke-dashoffset="${arc.offset}"></circle>`)
            .join('');
    },

    /** Donut segments for the structural health card. */
    get healthArcs() {
        const circumference = 2 * Math.PI * 52;
        let consumed = 0;

        return (this.dashboard?.health?.buckets ?? [])
            .filter((bucket) => bucket.percent > 0)
            .map((bucket) => {
                const length = (circumference * bucket.percent) / 100;
                const arc = {
                    key: bucket.key,
                    color: bucket.color,
                    dash: `${length} ${circumference - length}`,
                    offset: -consumed,
                };

                consumed += length;

                return arc;
            });
    },
});

Alpine.store('viewer', {
    open: false,
    loading: false,
    code: null,
    station: null,
    activeMetric: null,
    range: '24h',
    error: null,

    async open360(code) {
        this.open = true;
        this.loading = true;
        this.error = null;
        this.code = code;

        try {
            this.station = await getJson(`/api/stations/${code}`, { range: this.range });
            this.activeMetric = this.station.metrics.find((metric) => metric.is_primary)?.key
                ?? this.station.metrics[0]?.key
                ?? null;

            const url = new URL(window.location.href);
            url.pathname = `/digital-twin/${code}`;
            window.history.replaceState({ station: code }, '', url);

            // A previous station may have left the panel scrolled down.
            document.getElementById('station-panel')?.scrollTo({ top: 0 });
        } catch (error) {
            this.error = error.message;
        } finally {
            this.loading = false;
        }
    },

    async setRange(range) {
        this.range = range;

        if (this.code) {
            this.loading = true;
            this.station = await getJson(`/api/stations/${this.code}`, { range });
            this.loading = false;
        }
    },

    focusMetric(key) {
        this.activeMetric = key;
    },

    metric(key) {
        return this.station?.metrics.find((metric) => metric.key === key) ?? null;
    },

    close() {
        this.open = false;
        this.station = null;
        this.code = null;
        this.activeMetric = null;

        const url = new URL(window.location.href);
        url.pathname = '/digital-twin';
        window.history.replaceState({}, '', url);
    },
});

/* ------------------------------------------------------------------ *
 |  Directives
 * ------------------------------------------------------------------ */

/** Pointer-tracked specular highlight on glass surfaces. */
Alpine.directive('sheen', (element) => {
    const move = (event) => {
        const rect = element.getBoundingClientRect();
        element.style.setProperty('--sheen-x', `${((event.clientX - rect.left) / rect.width) * 100}%`);
        element.style.setProperty('--sheen-y', `${((event.clientY - rect.top) / rect.height) * 100}%`);
    };

    const leave = () => {
        element.style.removeProperty('--sheen-x');
        element.style.removeProperty('--sheen-y');
    };

    element.addEventListener('pointermove', move);
    element.addEventListener('pointerleave', leave);
});

/* ------------------------------------------------------------------ *
 |  Components
 * ------------------------------------------------------------------ */

Alpine.data('mapStage', mapStage);
Alpine.data('twinSphere', twinSphere);

Alpine.data('metricSpark', (metricKey) => ({
    chart: null,

    init() {
        this.draw();
        this.$watch('$store.viewer.station', () => this.draw());
    },

    async draw() {
        const metric = this.$store.viewer.metric(metricKey);

        if (!metric || !this.$el.clientWidth) {
            return;
        }

        this.chart = await sparkline(this.$el, metric.series ?? [], {
            color: statusColor(metric.status),
            unit: metric.unit,
            type: metric.chart_type,
        });
    },

    destroy() {
        disposeChart(this.$el);
    },
}));

/**
 * Analytics: a grid of every parameter, and one parameter in detail.
 *
 * The grid is the way in — all of a station's parameters at once, or the
 * headline parameter of every station, on one shared time range so they can
 * actually be compared. Picking a card drills into it; the station and the
 * range come along, because being made to choose them twice is what makes two
 * separate screens tiring.
 *
 * ECharts instances are never kept on this state — Alpine's proxy and a
 * canvas library do not mix. `getInstanceByDom` in the chart helpers is the
 * registry.
 */
/**
 * Analytics: every parameter as its own chart, or the chosen ones in one.
 *
 * `grafik` lays out a card per parameter — all of a station's, or the headline
 * parameter of every station — on one shared range, which is the only way
 * charts side by side can be compared. `analisa` puts the parameters the
 * reader ticks into a single chart; a card in the grid is a shortcut into it.
 *
 * ECharts instances are never kept on this state — Alpine's proxy and a canvas
 * library do not mix. `getInstanceByDom` in the chart helpers is the registry.
 */
Alpine.data('analyticsBoard', (stations) => ({
    stations,
    mode: 'grafik',
    scope: localStorage.getItem('analytics.scope') ?? (stations[0]?.code ?? ''),
    range: localStorage.getItem('analytics.range') ?? '7d',

    /** Series per grid card, keyed by card id. */
    data: {},

    /** What `analisa` is drawing: `{ code, key }` in the order they were picked. */
    picked: [],
    payloads: [],
    loading: false,

    init() {
        const stored = localStorage.getItem('analytics.mode');

        this.mode = stored === 'analisa' || stored === 'detail' ? 'analisa' : 'grafik';

        if (!this.stations.some((station) => station.code === this.scope) && this.scope !== 'semua') {
            this.scope = this.stations[0]?.code ?? '';
        }

        // Cards draw when they come into view: sixteen charts built at once is
        // a second of frozen page for rows nobody has scrolled to yet.
        this.slots = new Map();
        this.visible = new Set();
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const id = entry.target.dataset.cardId;

                if (!entry.isIntersecting) {
                    this.visible.delete(id);

                    return;
                }

                this.visible.add(id);
                this.drawCard(id);
            });
        }, { rootMargin: '160px' });

        this.picked = this.choices.slice(0, 1).map((choice) => ({ code: choice.code, key: choice.metric.key }));

        if (this.mode === 'analisa') {
            this.$nextTick(() => this.loadAnalysis());
        }

        window.addEventListener('resize', () => resizeCharts(this.$root));
    },

    /* --------------------------------------------------------- what exists */

    /**
     * Every parameter the current scope offers.
     *
     * One station: all of its parameters. Every station: the headline
     * parameter of each, which is what makes them comparable at a glance.
     */
    get choices() {
        if (this.scope === 'semua') {
            return this.stations
                .filter((station) => station.metrics.length)
                .map((station) => ({
                    id: station.code,
                    code: station.code,
                    station: station.name,
                    metric: station.metrics[0],
                }));
        }

        const station = this.stations.find((item) => item.code === this.scope);

        return (station?.metrics ?? []).map((metric) => ({
            id: `${station.code}:${metric.key}`,
            code: station.code,
            station: station.name,
            metric,
        }));
    },

    get cards() {
        return this.choices;
    },

    cardById(id) {
        return this.choices.find((choice) => choice.id === id) ?? null;
    },

    /* ---------------------------------------------------------------- grid */

    /** Register a card's canvas with the observer as Alpine renders it. */
    observe(element) {
        this.slots.set(element.dataset.cardId, element);
        this.observer.observe(element);
    },

    /**
     * Draw the cards that are on screen right now.
     *
     * The observer only reports a *change* in intersection, so coming back
     * from Analisa — where every card was `display: none` — left the grid
     * blank until something moved. This measures instead of waiting.
     */
    drawInView() {
        const height = window.innerHeight || 0;

        this.slots.forEach((element, id) => {
            const box = element.getBoundingClientRect();

            if (box.bottom > -160 && box.top < height + 160) {
                this.visible.add(id);
                this.drawCard(id);
            }
        });
    },

    async drawCard(id) {
        const card = this.cardById(id);
        const element = this.slots.get(id);

        if (!card || !element || this.data[id]?.pending) {
            return;
        }

        if (!this.data[id]) {
            this.data[id] = { pending: true };

            try {
                const payload = await getJson(`/api/stations/${card.code}/series/${card.metric.key}`, {
                    range: this.range,
                });

                this.data[id] = { pending: false, ...payload };
            } catch {
                this.data[id] = { pending: false, failed: true };

                return;
            }
        }

        const series = this.data[id];

        if (!series?.points) {
            return;
        }

        await seriesChart(element, [{
            name: series.metric.label,
            points: series.points,
            type: series.metric.chart_type,
        }], {
            unit: series.metric.unit,
            compact: true,
            zoom: false,
            thresholds: this.thresholdsOf(series.metric),
        });
    },

    thresholdsOf(metric) {
        return [
            { value: metric.normal_max, label: 'Batas Normal', color: '#34d399' },
            { value: metric.warning, label: 'Waspada', color: '#fbbf24' },
        ];
    },

    /* -------------------------------------------------------------- picking */

    isPicked(choice) {
        return this.picked.some((item) => item.code === choice.code && item.key === choice.metric.key);
    },

    /** Units already on the chart — two axes is the limit that stays readable. */
    get pickedUnits() {
        return [...new Set(this.picked.map((item) => this.metricOf(item)?.unit ?? ''))];
    },

    /**
     * Whether a parameter can join the chart.
     *
     * A third unit would need a third scale, and three scales on one chart is
     * a picture nobody can read — those chips are offered as disabled with the
     * reason on them.
     */
    canPick(choice) {
        if (this.isPicked(choice)) {
            return true;
        }

        return this.pickedUnits.length < 2 || this.pickedUnits.includes(choice.metric.unit ?? '');
    },

    togglePick(choice) {
        if (!this.canPick(choice)) {
            return;
        }

        const already = this.isPicked(choice);

        if (already && this.picked.length === 1) {
            return; // The chart must keep at least one series.
        }

        this.picked = already
            ? this.picked.filter((item) => !(item.code === choice.code && item.key === choice.metric.key))
            : [...this.picked, { code: choice.code, key: choice.metric.key }];

        this.loadAnalysis();
    },

    metricOf(item) {
        return this.stations
            .find((station) => station.code === item.code)
            ?.metrics.find((metric) => metric.key === item.key) ?? null;
    },

    stationOf(item) {
        return this.stations.find((station) => station.code === item.code) ?? null;
    },

    /* -------------------------------------------------------------- analisa */

    /** A card in the grid is a shortcut: that parameter alone, in analisa. */
    open(card) {
        this.picked = [{ code: card.code, key: card.metric.key }];
        this.setMode('analisa');
        this.loadAnalysis();
    },

    async loadAnalysis() {
        if (!this.picked.length) {
            return;
        }

        this.loading = true;

        try {
            const payloads = await Promise.all(this.picked.map((item) => getJson(
                `/api/stations/${item.code}/series/${item.key}`,
                { range: this.range },
            )));

            this.payloads = payloads;

            await this.$nextTick();

            const units = [...new Set(payloads.map((payload) => payload.metric.unit ?? ''))].slice(0, 2);

            const chart = await seriesChart(this.$refs.analysis, payloads.map((payload, index) => ({
                name: this.picked.length > 1 && this.scope === 'semua'
                    ? `${this.stationOf(this.picked[index])?.name ?? ''} — ${payload.metric.label}`
                    : payload.metric.label,
                points: payload.points,
                type: payload.metric.chart_type,
                axis: Math.max(0, units.indexOf(payload.metric.unit ?? '')),
            })), {
                units,
                // Thresholds belong to one parameter; drawing every set turns
                // the chart into a ladder.
                thresholds: payloads.length === 1 ? this.thresholdsOf(payloads[0].metric) : [],
            });

            // The panel may have been hidden when the instance was created.
            chart.resize();
        } finally {
            this.loading = false;
        }
    },

    /** The series the statistics belong to: the first one picked. */
    get primary() {
        return this.payloads[0] ?? null;
    },

    /* --------------------------------------------------------------- modes */

    setMode(mode) {
        this.mode = mode;
        localStorage.setItem('analytics.mode', mode);

        /*
        | A chart built while its container was `display: none` measured zero
        | and stayed a sliver. Whichever view is coming into sight gets a
        | resize once the browser has laid it out.
        */
        this.$nextTick(() => {
            if (mode === 'grafik') {
                this.drawInView();
            } else if (!this.payloads.length) {
                this.loadAnalysis();
            }

            resizeCharts(this.$root);
        });
    },

    backToGrid() {
        this.setMode('grafik');
    },

    /** Everything already fetched is stale once the range or station changes. */
    async refresh() {
        this.data = {};
        this.observer.disconnect();
        this.slots.clear();
        this.visible.clear();

        // Alpine re-renders the cards, and their `x-init` observes them again.
        await this.$nextTick();

        if (this.mode === 'analisa') {
            this.loadAnalysis();

            return;
        }

        this.drawInView();
    },

    setScope(code) {
        this.scope = code;
        localStorage.setItem('analytics.scope', code);

        // The parameters on offer changed with the scope; keep the ones that
        // are still on offer, and fall back to the first if none are.
        const offered = this.choices;

        this.picked = this.picked.filter((item) => offered.some(
            (choice) => choice.code === item.code && choice.metric.key === item.key,
        ));

        if (!this.picked.length) {
            this.picked = offered.slice(0, 1).map((choice) => ({ code: choice.code, key: choice.metric.key }));
        }

        this.refresh();
    },

    setRange(range) {
        this.range = range;
        localStorage.setItem('analytics.range', range);
        this.refresh();
    },

    /* --------------------------------------------------------------- shared */

    /** Last reading of a card, formatted, or a dash while it is still coming. */
    lastOf(id) {
        const points = this.data[id]?.points;

        return points?.length ? this.number(points.at(-1).v) : '—';
    },

    /** Change across the window on screen — the reason to look twice. */
    deltaOf(id) {
        const points = this.data[id]?.points;

        if (!points || points.length < 2) {
            return null;
        }

        return points.at(-1).v - points[0].v;
    },

    statusOf(code) {
        return this.$store.site.markerByCode(code)?.status ?? null;
    },

    number(value) {
        return Number.isFinite(value)
            ? Number(value).toLocaleString('id-ID', { maximumFractionDigits: 3 })
            : '—';
    },

    signed(value) {
        if (!Number.isFinite(value)) {
            return '—';
        }

        return `${value > 0 ? '+' : ''}${this.number(value)}`;
    },
}));

Alpine.data('reportForm', () => ({
    period: 'harian',
    format: 'pdf',
    start: new Date().toISOString().slice(0, 10),
    end: new Date().toISOString().slice(0, 10),
    sections: ['ringkasan', 'parameter', 'peringatan'],
    busy: false,
    result: null,
    error: null,

    async submit() {
        this.busy = true;
        this.error = null;

        try {
            const response = await postJson('/api/reports', {
                period: this.period,
                period_start: this.start,
                period_end: this.end,
                format: this.format,
                sections: this.sections,
            });

            this.result = response.data;
            window.location.href = `/api/reports/${response.data.id}/download`;
        } catch (error) {
            this.error = error.message;
        } finally {
            this.busy = false;
        }
    },
}));

/**
 * Station search in the header.
 *
 * On the digital twin it asks the stage to turn to the pin (the stage listens
 * for `focus-station`); on any other page there is no stage to turn, so it
 * opens that station's panorama instead.
 */
/**
 * The maintenance desk: jobs, their conversation, and the log of what has been
 * done. The control room and the service desk share the same threads, so the
 * request, the answer and the outcome stay together instead of in a chat app.
 */
Alpine.data('maintenanceDesk', (tickets, history, desk) => ({
    tab: 'pesan',
    tickets,
    history,
    desk,
    activeId: tickets.find((ticket) => ticket.unread > 0)?.id ?? tickets[0]?.id ?? null,
    scope: 'aktif',
    stationFilter: '',
    draft: '',
    sending: false,
    error: null,

    form: { open: false, title: '', station: '', type: 'korektif', priority: 'normal', body: '' },

    /**
     * Where the unread run started when this ticket was opened.
     *
     * Opening a thread marks it read, so the boundary has to be remembered
     * before that happens or the divider would vanish in the same breath.
     */
    unreadFrom: null,

    /**
     * A message this reader wrote.
     *
     * The side of the desk is not enough: two operators share a side, and a
     * colleague's line hanging on your own margin reads as your own. Older
     * rows without an author fall back to the side.
     */
    mine(message) {
        return message.user_id != null && this.desk.user_id != null
            ? message.user_id === this.desk.user_id
            : message.role === this.desk.role;
    },

    /** Two letters for the avatar; a name is not always two words. */
    initials(name) {
        const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);

        if (parts.length === 0) {
            return '?';
        }

        return (parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[1][0]).toUpperCase();
    },

    /** Only the last of your own messages carries a receipt. */
    lastMine(ticket) {
        return [...(ticket?.messages ?? [])].reverse().find((message) => this.mine(message))?.id ?? null;
    },

    sideLabel(role) {
        return this.desk.sides?.[role] ?? role;
    },

    /**
     * The thread as it is read: day headings, and bursts by one author.
     *
     * A name and an avatar on every line of a five-line answer is noise; the
     * burst carries them once and the clock sits under its last line.
     */
    get thread() {
        const messages = this.active?.messages ?? [];
        const rows = [];
        let day = null;
        let previous = null;

        messages.forEach((message, index) => {
            if (message.day && message.day !== day) {
                day = message.day;
                rows.push({ type: 'day', id: `day-${message.id}`, label: day });
                previous = null;
            }

            if (message.id === this.unreadFrom) {
                rows.push({ type: 'unread', id: `unread-${message.id}` });
                previous = null;
            }

            const next = messages[index + 1];

            rows.push({
                type: 'message',
                id: message.id,
                message,
                // Same author, same day, and nothing in between.
                grouped: previous?.user_id === message.user_id
                    && previous?.role === message.role
                    && previous?.day === message.day,
                // The clock prints once per burst, under its last line.
                last: !next || next.user_id !== message.user_id || next.day !== message.day,
            });

            previous = message;
        });

        return rows;
    },

    /** Newest word in a ticket, for the list on the left. */
    preview(ticket) {
        const message = ticket.messages?.at(-1);

        if (!message) {
            return 'Belum ada percakapan.';
        }

        return `${this.mine(message) ? 'Anda: ' : ''}${message.body}`;
    },

    previewTime(ticket) {
        return ticket.messages?.at(-1)?.clock ?? '';
    },

    get visible() {
        if (this.scope === 'semua') {
            return this.tickets;
        }

        return this.tickets.filter((ticket) => (this.scope === 'riwayat'
            ? ticket.status === 'selesai'
            : ticket.status !== 'selesai'));
    },

    get active() {
        return this.tickets.find((ticket) => ticket.id === this.activeId) ?? null;
    },

    get historyRows() {
        return this.stationFilter
            ? this.history.filter((row) => row.station_code === this.stationFilter)
            : this.history;
    },

    init() {
        this.$nextTick(() => this.open(this.activeId));
    },

    async open(id) {
        this.activeId = id;

        const ticket = this.active;

        // Remember the boundary before the read call wipes it.
        this.unreadFrom = ticket?.messages?.find(
            (message) => !message.read && message.role !== this.desk.role,
        )?.id ?? null;

        this.$nextTick(() => this.scrollThread());

        if (!ticket || ticket.unread === 0) {
            return;
        }

        try {
            const response = await postJson(`/api/maintenance/${ticket.id}/read`, {});
            ticket.unread = 0;
            ticket.messages.forEach((message) => { message.read = true; });
            this.$store.site.maintenanceUnread = response.unread ?? 0;
        } catch {
            // Reading is a convenience; a failure here must not block the desk.
        }
    },

    async send() {
        const body = this.draft.trim();

        if (!body || !this.active || this.sending) {
            return;
        }

        this.sending = true;
        this.error = null;

        try {
            const response = await postJson(`/api/maintenance/${this.active.id}/messages`, { body });
            this.active.messages.push(response.data);
            this.draft = '';
            this.$nextTick(() => this.scrollThread());
        } catch {
            this.error = 'Pesan gagal terkirim. Coba lagi.';
        } finally {
            this.sending = false;
        }
    },

    async submitRequest() {
        if (!this.form.title.trim() || this.sending) {
            return;
        }

        this.sending = true;
        this.error = null;

        try {
            const response = await postJson('/api/maintenance/requests', {
                title: this.form.title,
                station: this.form.station || null,
                type: this.form.type,
                priority: this.form.priority,
                body: this.form.body || null,
            });

            this.tickets.unshift(response.data);
            this.form = { open: false, title: '', station: '', type: 'korektif', priority: 'normal', body: '' };
            this.tab = 'pesan';
            this.open(response.data.id);
        } catch {
            this.error = 'Permintaan gagal dikirim. Periksa isian lalu coba lagi.';
        } finally {
            this.sending = false;
        }
    },

    openRequest() {
        this.error = null;
        this.form.open = true;
        this.tab = 'pesan';

        this.$nextTick(() => {
            const scrim = [...document.querySelectorAll('.modal-scrim')]
                .find((element) => (element.checkVisibility ? element.checkVisibility() : element.offsetParent !== null));

            scrim?.querySelector('input:not([type=hidden]), textarea, select')?.focus();
        });
    },

    closeRequest() {
        this.form.open = false;
    },

    scrollThread() {
        const box = this.$refs.thread;

        if (box) {
            box.scrollTop = box.scrollHeight;
        }
    },
}));

/**
 * Accounts and roles: one list, four modals.
 *
 * Every write is an ordinary form post, so validation, CSRF and the redirect
 * stay the server's job; this only decides which dialog is open and what it
 * starts filled with. A save that comes back with errors reopens its dialog
 * holding what was typed (`reopen`).
 */
Alpine.data('accessDesk', ({ users = [], roles = [], reopen = null }) => ({
    users,
    roles,
    tab: reopen?.tab ?? 'pengguna',
    modal: reopen?.modal ?? null,
    target: reopen?.target ?? null,
    deleteKind: null,
    draft: {},
    resetTimer: null,

    init() {
        this.draft = reopen
            ? { ...this.blank(), ...(reopen.draft ?? {}) }
            : this.blank();
    },

    blank() {
        return {
            name: '',
            email: '',
            role: this.roles[0]?.slug ?? 'operator',
            unit: '',
            description: '',
            desk_side: 'operator',
            is_active: true,
            permissions: [],
        };
    },

    openUser(user = null) {
        this.target = user;
        this.draft = user
            ? {
                ...this.blank(),
                name: user.name,
                email: user.email,
                role: user.role,
                unit: user.unit ?? '',
                is_active: user.is_active,
            }
            : this.blank();

        this.show('user');
    },

    openRole(role = null) {
        this.target = role;
        this.draft = role
            ? {
                ...this.blank(),
                name: role.name,
                description: role.description ?? '',
                desk_side: role.desk_side,
                permissions: [...(role.permissions ?? [])],
            }
            : this.blank();

        this.show('role');
    },

    askDelete(kind, item) {
        this.deleteKind = kind;
        this.target = item;
        this.show('delete');
    },

    show(modal) {
        clearTimeout(this.resetTimer);
        this.modal = modal;

        /*
        | The dialogs are teleported to <body>, so their refs do not reach this
        | component: take the first field of whichever scrim is on screen. The
        | extra frame is the transition — focus() does nothing while the card is
        | still display:none.
        */
        this.$nextTick(() => requestAnimationFrame(() => {
            const card = [...document.querySelectorAll('.modal-scrim')]
                .find((el) => (el.checkVisibility ? el.checkVisibility() : el.offsetParent !== null));

            card?.querySelector('input:not([type=hidden]), select, textarea, button[type=submit]')?.focus();
        }));
    },

    close() {
        this.modal = null;

        /*
        | What the dialog was showing has to survive its own leave animation.
        | Dropping `target` now rewrites the card while it is still on screen:
        | the titles flip to the "new" wording and the administrator's dialog
        | grows its ability grid back, which reads as a blink on the way out.
        */
        clearTimeout(this.resetTimer);

        this.resetTimer = setTimeout(() => {
            if (this.modal === null) {
                this.target = null;
                this.deleteKind = null;
            }
        }, 260);
    },

    /** The administrator's abilities are set by the server, not by the form. */
    get isAdminRole() {
        return this.target?.slug === 'admin';
    },
}));

Alpine.data('stationSearch', () => ({
    query: '',
    open: false,

    get results() {
        const query = this.query.trim().toLowerCase();

        if (!query) {
            return [];
        }

        return this.$store.site.markers
            .filter((marker) => `${marker.name} ${marker.code} ${marker.zone ?? ''} ${marker.type_label}`
                .toLowerCase()
                .includes(query))
            .slice(0, 8);
    },

    toggle() {
        this.open = !this.open;

        if (this.open) {
            this.$nextTick(() => this.$refs.field?.focus());
        }
    },

    close() {
        this.open = false;
    },

    pick(marker) {
        if (!marker) {
            return;
        }

        this.query = '';
        this.open = false;

        if (window.location.pathname.startsWith('/digital-twin')) {
            window.dispatchEvent(new CustomEvent('focus-station', { detail: marker.code }));

            return;
        }

        window.location.href = `/digital-twin/${marker.code}`;
    },
}));

Alpine.data('thresholdForm', (metrics) => ({
    rows: metrics,
    saving: false,
    saved: false,
    failed: false,

    /** Rows touched since the last save, so edits cannot be lost silently. */
    dirty: new Set(),

    async save() {
        this.saving = true;
        this.failed = false;

        try {
            await postJson('/api/settings', {
                thresholds: this.rows.map((row) => ({
                    id: row.id,
                    warning_threshold: row.warning_threshold,
                    alert_threshold: row.alert_threshold,
                    critical_threshold: row.critical_threshold,
                })),
            });

            this.dirty.clear();
            this.saved = true;
            window.setTimeout(() => {
                this.saved = false;
            }, 2400);
        } catch {
            this.failed = true;
        } finally {
            this.saving = false;
        }
    },
}));

/* ------------------------------------------------------------------ *
 |  Helpers exposed to templates
 * ------------------------------------------------------------------ */

window.statusColor = statusColor;
window.relativeTime = relativeTime;
window.iconSvg = iconSvg;

// `/` jumps to the station search unless the reader is already typing. The
// field only exists on the digital twin, so elsewhere this finds nothing and
// the key keeps its normal meaning.
window.addEventListener('keydown', (event) => {
    if (event.key !== '/' || event.metaKey || event.ctrlKey) {
        return;
    }

    const tag = document.activeElement?.tagName;

    if (tag === 'INPUT' || tag === 'TEXTAREA' || document.activeElement?.isContentEditable) {
        return;
    }

    const field = document.querySelector('[x-data="stationSearch()"] input');

    if (field) {
        event.preventDefault();
        field.focus();
    }
});
window.Alpine = Alpine;

/*
 * Navigation feedback.
 *
 * Every page is server rendered, so a click on the rail is followed by a wait
 * the reader cannot see. The bar starts on the click that actually leaves the
 * page (plain left click, no modifier, same tab) and on form submits, and it
 * goes away with the document. `pageshow` covers coming back through the
 * history cache, where this document is reused.
 */
function navigationFeedback() {
    const bar = document.querySelector('[data-nav-progress]');

    if (!bar) {
        return;
    }

    const start = () => {
        bar.classList.add('nav-progress--busy');
        // Free the server for the page being asked for.
        window.Alpine?.store('site')?.pausePolling?.();
    };
    const stop = () => bar.classList.remove('nav-progress--busy');

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest?.('a[href]');

        if (!link || link.target === '_blank' || link.hasAttribute('download')) {
            return;
        }

        const url = new URL(link.href, window.location.href);

        // Same page, or a jump within it: nothing is being loaded.
        if (url.origin !== window.location.origin || url.href === window.location.href) {
            return;
        }

        start();
    });

    document.addEventListener('submit', (event) => {
        if (!event.defaultPrevented) {
            start();
        }
    });

    window.addEventListener('pageshow', stop);
}

navigationFeedback();

Alpine.start();

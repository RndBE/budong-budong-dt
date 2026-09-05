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

    start(boot, dashboard = null) {
        this.boot = boot;
        this.environment = boot.environment;
        this.markers = boot.markers ?? [];
        this.dashboard = dashboard;
        this.skin = boot.skin ?? 'auto';

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

        this.stopPolling.push(poll(() => this.refreshEnvironment(), boot.refresh.environment));
        this.stopPolling.push(poll(() => this.refreshDashboard(), boot.refresh.dashboard));

        // Pages that render the summary panel without a server-side payload.
        if (!dashboard) {
            this.refreshDashboard().catch(() => {});
        }
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

    async refreshDashboard() {
        this.dashboard = await getJson('/api/dashboard');
        this.markers = (await getJson('/api/stations')).data;
    },

    /** Background layers for the current sun phase, or a manual override. */
    get scene() {
        const scene = this.environment?.scene;

        if (!scene) {
            return { primary: null, secondary: null, mix: 0, grade: {} };
        }

        const simulated = this.simulated;

        if (simulated && this.skin === 'auto') {
            return {
                primary: scene.assets[simulated.primary],
                secondary: scene.assets[simulated.secondary],
                mix: simulated.mix,
                grade: simulated.grade,
                phase: simulated.primary,
                phase_label: simulated.phase_label,
                daylight: simulated.daylight,
            };
        }

        if (this.skin !== 'auto') {
            return {
                primary: scene.assets[this.skin],
                secondary: scene.assets[this.skin],
                mix: 0,
                grade: scene.grade,
                phase: this.skin,
            };
        }

        return {
            primary: scene.assets[scene.primary],
            secondary: scene.assets[scene.secondary],
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

Alpine.data('analyticsBoard', (stations) => ({
    stations,
    stationCode: stations[0]?.code ?? null,
    metricKey: stations[0]?.metrics[0]?.key ?? null,
    range: '7d',
    loading: false,
    payload: null,

    init() {
        this.load();
        window.addEventListener('resize', () => resizeCharts(this.$root));
    },

    get metrics() {
        return this.stations.find((station) => station.code === this.stationCode)?.metrics ?? [];
    },

    onStationChange() {
        this.metricKey = this.metrics[0]?.key ?? null;
        this.load();
    },

    async load() {
        if (!this.stationCode || !this.metricKey) {
            return;
        }

        this.loading = true;

        try {
            this.payload = await getJson(`/api/stations/${this.stationCode}/series/${this.metricKey}`, {
                range: this.range,
            });

            this.$nextTick(async () => {
                await seriesChart(this.$refs.chart, [
                    {
                        name: this.payload.metric.label,
                        points: this.payload.points,
                        type: this.payload.metric.chart_type,
                    },
                ], {
                    unit: this.payload.metric.unit,
                    thresholds: [
                        { value: this.payload.metric.normal_max, label: 'Batas Normal', color: '#34d399' },
                        { value: this.payload.metric.warning, label: 'Waspada', color: '#fbbf24' },
                    ],
                });
            });
        } finally {
            this.loading = false;
        }
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
window.Alpine = Alpine;

Alpine.start();

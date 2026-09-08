/**
 * ECharts wrapper. The library is heavy, so it is imported on demand: only
 * pages that actually draw a chart pay for it.
 */

let enginePromise = null;

function loadEngine() {
    enginePromise ??= (async () => {
        /*
        | Destructured, not namespaced.
        |
        | `import('echarts/components')` and then reading `components.X` is a
        | dynamic property access, so the bundler has to keep every component
        | in the chunk — including the map ones, which is a quarter of a
        | megabyte of GeoJSON parsing for a line chart. Naming the bindings
        | lets Rollup drop what no chart here asks for — and each import is
        | destructured on its own line, because handing the namespace to
        | `Promise.all` first hides which bindings are used and brings the
        | whole library back.
        */
        const core = await import('echarts/core');
        const { LineChart, BarChart } = await import('echarts/charts');
        const {
            GridComponent,
            TooltipComponent,
            MarkLineComponent,
            MarkAreaComponent,
            DataZoomComponent,
            LegendComponent,
        } = await import('echarts/components');
        const { CanvasRenderer } = await import('echarts/renderers');

        core.use([
            LineChart,
            BarChart,
            GridComponent,
            TooltipComponent,
            MarkLineComponent,
            MarkAreaComponent,
            DataZoomComponent,
            LegendComponent,
            CanvasRenderer,
        ]);

        return core;
    })();

    return enginePromise;
}

/*
 * Charts that were built while their container was hidden.
 *
 * ECharts measures the element at `init`, and a `display: none` container
 * measures zero — the library then falls back to 100px and stays there, which
 * is how the analytics detail chart ended up a sliver of its panel. One
 * observer per element resizes it the moment the layout gives it a size, so a
 * chart in a tab, a dialog or a folded panel comes out right without every
 * caller having to remember to resize it.
 */
const watched = new WeakSet();

function watchSize(element, echarts) {
    if (watched.has(element) || typeof ResizeObserver === 'undefined') {
        return;
    }

    watched.add(element);

    new ResizeObserver(() => {
        const box = element.getBoundingClientRect();
        const chart = echarts.getInstanceByDom(element);

        if (chart && !chart.isDisposed() && box.width > 0 && box.height > 0) {
            chart.resize();
        }
    }).observe(element);
}

/*
| Everything else that moves in this app asks first; a chart drawing itself
| across the screen is no different.
*/
const stillness = () => typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const PALETTE = ['#47a6ff', '#34d399', '#fbbf24', '#c084fc', '#fb923c', '#38bdf8'];

const areaGradient = (echarts, color) =>
    new echarts.graphic.LinearGradient(0, 0, 0, 1, [
        { offset: 0, color: `${color}66` },
        { offset: 1, color: `${color}00` },
    ]);

/**
 * A chart needs somewhere to live.
 *
 * `echarts.init(undefined)` reads `getAttribute` off it and throws a message
 * that names nothing, which is a long way from the caller that lost its
 * element. Fail here instead, saying what happened.
 */
function surface(element, who) {
    if (!element || !element.nodeType) {
        throw new Error(`Tidak ada elemen untuk grafik ${who}.`);
    }

    return element;
}

function toPairs(points) {
    return points.map((point) => [point.t, point.v]);
}

/** Compact trend line used inside the station metric cards. */
export async function sparkline(element, points, options = {}) {
    surface(element, 'sparkline');

    const echarts = await loadEngine();
    const color = options.color ?? PALETTE[0];
    const chart = echarts.getInstanceByDom(element) ?? echarts.init(element, null, { renderer: 'canvas' });

    watchSize(element, echarts);

    chart.setOption(
        {
            animation: ! stillness(),
            animationDuration: 420,
            animationEasing: 'cubicOut',
            animationDurationUpdate: 320,
            animationEasingUpdate: 'cubicInOut',
            grid: { top: 6, right: 4, bottom: 4, left: 4 },
            xAxis: { type: 'time', show: false },
            yAxis: { type: 'value', show: false, scale: true },
            tooltip: {
                trigger: 'axis',
                backgroundColor: 'rgba(6, 20, 32, 0.92)',
                borderColor: 'rgba(255,255,255,0.14)',
                textStyle: { color: '#e4eefb', fontSize: 11 },
                axisPointer: { type: 'line', lineStyle: { color: 'rgba(255,255,255,0.25)' } },
                valueFormatter: (value) => `${Number(value).toLocaleString('id-ID', { maximumFractionDigits: 3 })} ${options.unit ?? ''}`.trim(),
            },
            series: [
                {
                    type: options.type === 'bar' ? 'bar' : 'line',
                    data: toPairs(points),
                    smooth: 0.35,
                    symbol: 'none',
                    lineStyle: { width: 1.8, color },
                    itemStyle: { color },
                    areaStyle: options.type === 'bar' ? undefined : { color: areaGradient(echarts, color) },
                    barWidth: '55%',
                },
            ],
        },
        { notMerge: true },
    );

    return chart;
}

/** Full chart with thresholds, used on the analytics page. */
export async function seriesChart(element, datasets, options = {}) {
    surface(element, 'seri');

    const echarts = await loadEngine();
    const chart = echarts.getInstanceByDom(element) ?? echarts.init(element, null, { renderer: 'canvas' });

    watchSize(element, echarts);

    const markLines = (options.thresholds ?? [])
        .filter((threshold) => threshold.value !== null && threshold.value !== undefined)
        .map((threshold) => ({
            yAxis: threshold.value,
            label: {
                formatter: threshold.label,
                position: 'insideEndTop',
                color: threshold.color,
                fontSize: 10,
            },
            lineStyle: { color: threshold.color, type: 'dashed', width: 1 },
        }));

    chart.setOption(
        {
            color: PALETTE,
            /*
            | Arriving and changing are two different movements. A chart is
            | rebuilt with `notMerge` whenever the picked parameters change, so
            | without an update pair every swap replayed the full entrance —
            | the picture snapped rather than moved. The entrance eases out
            | (fast, then settling); a change eases both ends, because the eye
            | is already on the line and a hard start reads as a flicker.
            */
            animation: ! stillness(),
            animationDuration: 560,
            animationEasing: 'cubicOut',
            animationDurationUpdate: 420,
            animationEasingUpdate: 'cubicInOut',
            // A card in the grid is a fifth the height of the detail chart, so
            // it cannot spend 44px on a zoom slider it does not have.
            grid: options.compact
                ? { top: 16, right: 12, bottom: 22, left: 44 }
                : { top: 28, right: 18, bottom: 44, left: 52 },
            legend: {
                show: datasets.length > 1,
                top: 0,
                right: 0,
                textStyle: { color: '#9db4cc', fontSize: 11 },
                icon: 'roundRect',
                itemHeight: 8,
                itemWidth: 14,
            },
            tooltip: {
                trigger: 'axis',
                backgroundColor: 'rgba(6, 20, 32, 0.94)',
                borderColor: 'rgba(255,255,255,0.14)',
                textStyle: { color: '#e4eefb', fontSize: 12 },
            },
            xAxis: {
                type: 'time',
                axisLine: { lineStyle: { color: 'rgba(255,255,255,0.14)' } },
                axisLabel: { color: '#7590ab', fontSize: options.compact ? 9 : 10, hideOverlap: true },
                splitLine: { show: false },
            },
            /*
            | One axis per unit, at most two.
            |
            | Series of different quantities can share a chart only if each
            | reads against its own scale — and each axis has to say which unit
            | it carries, or the picture invites a comparison nobody made.
            */
            yAxis: (options.units ?? [options.unit ?? '']).map((unit, index) => ({
                type: 'value',
                scale: true,
                position: index === 0 ? 'left' : 'right',
                name: options.compact ? '' : unit,
                nameTextStyle: { color: '#7590ab', fontSize: 10, align: index === 0 ? 'left' : 'right' },
                axisLabel: { color: '#7590ab', fontSize: options.compact ? 9 : 10 },
                splitLine: index === 0 ? { lineStyle: { color: 'rgba(255,255,255,0.06)' } } : { show: false },
            })),
            dataZoom: options.zoom === false ? [] : [
                { type: 'inside', throttle: 60 },
                {
                    type: 'slider',
                    height: 16,
                    bottom: 6,
                    borderColor: 'transparent',
                    backgroundColor: 'rgba(255,255,255,0.05)',
                    fillerColor: 'rgba(71,166,255,0.18)',
                    handleStyle: { color: '#47a6ff' },
                    textStyle: { color: '#7590ab', fontSize: 9 },
                },
            ],
            series: datasets.map((dataset, index) => ({
                name: dataset.name,
                type: dataset.type === 'bar' ? 'bar' : 'line',
                yAxisIndex: dataset.axis ?? 0,
                data: toPairs(dataset.points ?? []),
                /*
                | The line draws itself left to right rather than appearing
                | whole: a point every couple of milliseconds, capped so a
                | thirty-day series does not take a second and a half to
                | arrive. A second series follows the first by a beat, which is
                | what makes two of them read as two.
                */
                animationDelay: (point) => Math.min(point * 1.6, 320) + index * 90,
                animationDelayUpdate: (point) => Math.min(point * 0.8, 160),
                smooth: 0.3,
                symbol: 'none',
                lineStyle: { width: 2 },
                areaStyle: dataset.type === 'bar' || datasets.length > 1
                    ? undefined
                    : { color: areaGradient(echarts, PALETTE[index % PALETTE.length]) },
                markLine: index === 0 && markLines.length ? { silent: true, symbol: 'none', data: markLines } : undefined,
            })),
        },
        { notMerge: true },
    );

    return chart;
}

export async function resizeCharts(root = document) {
    if (!enginePromise) {
        return;
    }

    const echarts = await enginePromise;
    root.querySelectorAll('[data-chart]').forEach((element) => {
        echarts.getInstanceByDom(element)?.resize();
    });
}

export async function disposeChart(element) {
    if (!enginePromise) {
        return;
    }

    const echarts = await enginePromise;
    echarts.getInstanceByDom(element)?.dispose();
}

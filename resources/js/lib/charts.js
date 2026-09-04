/**
 * ECharts wrapper. The library is heavy, so it is imported on demand: only
 * pages that actually draw a chart pay for it.
 */

let enginePromise = null;

function loadEngine() {
    enginePromise ??= (async () => {
        const [core, charts, components, renderers] = await Promise.all([
            import('echarts/core'),
            import('echarts/charts'),
            import('echarts/components'),
            import('echarts/renderers'),
        ]);

        core.use([
            charts.LineChart,
            charts.BarChart,
            components.GridComponent,
            components.TooltipComponent,
            components.MarkLineComponent,
            components.MarkAreaComponent,
            components.DataZoomComponent,
            components.LegendComponent,
            renderers.CanvasRenderer,
        ]);

        return core;
    })();

    return enginePromise;
}

const PALETTE = ['#47a6ff', '#34d399', '#fbbf24', '#c084fc', '#fb923c', '#38bdf8'];

const areaGradient = (echarts, color) =>
    new echarts.graphic.LinearGradient(0, 0, 0, 1, [
        { offset: 0, color: `${color}66` },
        { offset: 1, color: `${color}00` },
    ]);

function toPairs(points) {
    return points.map((point) => [point.t, point.v]);
}

/** Compact trend line used inside the station metric cards. */
export async function sparkline(element, points, options = {}) {
    const echarts = await loadEngine();
    const color = options.color ?? PALETTE[0];
    const chart = echarts.getInstanceByDom(element) ?? echarts.init(element, null, { renderer: 'canvas' });

    chart.setOption(
        {
            animationDuration: 420,
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
    const echarts = await loadEngine();
    const chart = echarts.getInstanceByDom(element) ?? echarts.init(element, null, { renderer: 'canvas' });

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
            animationDuration: 520,
            grid: { top: 28, right: 18, bottom: 44, left: 52 },
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
                axisLabel: { color: '#7590ab', fontSize: 10, hideOverlap: true },
                splitLine: { show: false },
            },
            yAxis: {
                type: 'value',
                scale: true,
                name: options.unit ?? '',
                nameTextStyle: { color: '#7590ab', fontSize: 10, align: 'left' },
                axisLabel: { color: '#7590ab', fontSize: 10 },
                splitLine: { lineStyle: { color: 'rgba(255,255,255,0.06)' } },
            },
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
                data: toPairs(dataset.points ?? []),
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

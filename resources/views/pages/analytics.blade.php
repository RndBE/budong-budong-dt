@extends('layouts.app')

@section('title', 'Analisa & Grafik')

@section('stage')
    <x-page-shell wide title="Analisa & Grafik"
                  subtitle="Bandingkan tren tiap parameter terhadap ambang batasnya.">

        <div class="glass glass--panel p-4" x-sheen x-data="analyticsBoard(@js($stations))">
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <label class="min-w-[240px] flex-1">
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Stasiun</span>
                    <select class="glass glass--inset w-full appearance-none px-3.5 py-2.5 text-[12.5px] text-white focus:outline-none"
                            x-model="stationCode" @change="onStationChange()">
                        <template x-for="station in stations" :key="station.code">
                            <option class="bg-ink-800" :value="station.code" x-text="station.name"></option>
                        </template>
                    </select>
                </label>

                <label class="min-w-[220px] flex-1">
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Parameter</span>
                    <select class="glass glass--inset w-full appearance-none px-3.5 py-2.5 text-[12.5px] text-white focus:outline-none"
                            x-model="metricKey" @change="load()">
                        <template x-for="metric in metrics" :key="metric.key">
                            <option class="bg-ink-800" :value="metric.key"
                                    x-text="metric.label + (metric.unit ? ` (${metric.unit})` : '')"></option>
                        </template>
                    </select>
                </label>

                <div class="glass glass--inset flex items-center gap-0.5 p-0.5">
                    <template x-for="option in ['24h', '7d', '30d']" :key="'range' + option">
                        <button type="button" class="rounded-xl px-3 py-1.5 text-[11.5px] font-semibold transition"
                                :class="range === option ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                @click="range = option; load()" x-text="option"></button>
                    </template>
                </div>

                <span class="text-[11px] text-mist-400" x-show="loading">memuat…</span>
            </div>

            <div class="h-[420px] w-full max-lg:h-[320px] max-sm:h-[260px]" data-chart x-ref="chart"></div>

            <div class="mt-3 grid grid-cols-4 gap-3">
                <template x-for="stat in [
                    { label: 'Nilai Terakhir', value: payload?.points?.at(-1)?.v },
                    { label: 'Minimum', value: payload ? Math.min(...payload.points.map(p => p.v)) : null },
                    { label: 'Maksimum', value: payload ? Math.max(...payload.points.map(p => p.v)) : null },
                    { label: 'Rata-rata', value: payload && payload.points.length
                        ? payload.points.reduce((sum, p) => sum + p.v, 0) / payload.points.length
                        : null },
                ]" :key="stat.label">
                    <div class="glass glass--inset px-3.5 py-3">
                        <p class="text-[11px] text-mist-300" x-text="stat.label"></p>
                        <p class="tnum mt-1 text-[17px] font-bold text-white">
                            <span x-text="stat.value === null || stat.value === undefined || !Number.isFinite(stat.value)
                                ? '—'
                                : Number(stat.value).toLocaleString('id-ID', { maximumFractionDigits: 3 })"></span>
                            <span class="text-[11px] font-medium text-mist-300" x-text="payload?.metric?.unit ?? ''"></span>
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </x-page-shell>
@endsection

@section('panel')
    <div></div>
@endsection

{{-- Right panel while the 360 viewer is open: detail of the selected marker. --}}
<div id="station-panel" class="scroll-y flex h-full flex-col gap-3.5 pr-0.5" x-show="$store.viewer.open" x-cloak>

    {{-- Identity + status --}}
    <div class="glass glass--panel panel-enter shrink-0 p-4" x-sheen>
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-[10.5px] font-semibold tracking-[.12em] text-brand-300 uppercase"
                   x-text="$store.viewer.station?.type_label"></p>
                <h2 class="truncate text-[16px] font-bold text-white" x-text="$store.viewer.station?.name"></h2>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-mist-300">
                    <span x-text="$store.viewer.station?.code?.toUpperCase()"></span>
                    <span class="text-mist-500">·</span>
                    <span x-text="$store.viewer.station?.zone"></span>
                    <template x-if="$store.viewer.station?.elevation">
                        <span class="tnum">· El. <span x-text="$store.viewer.station.elevation"></span> mdpl</span>
                    </template>
                </p>
            </div>

            <span class="glass glass--chip glass--flat mt-0.5 flex shrink-0 items-center gap-1.5 px-2.5 py-1.5 text-[11px] font-semibold"
                  :style="`color:${window.statusColor($store.viewer.station?.status ?? 'normal')}`">
                <span class="status-dot" :style="`background:${window.statusColor($store.viewer.station?.status ?? 'normal')}`"></span>
                <span x-text="$store.viewer.station?.status_label"></span>
            </span>
        </div>

        <p class="mt-3 text-[11.5px] leading-relaxed text-mist-300" x-text="$store.viewer.station?.description"></p>

        <div class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
            <div class="glass glass--inset px-2.5 py-2">
                <p class="text-mist-400">Perangkat</p>
                <p class="truncate font-medium text-mist-100" x-text="$store.viewer.station?.model ?? '—'"></p>
            </div>
            <div class="glass glass--inset px-2.5 py-2">
                <p class="text-mist-400">Kalibrasi</p>
                <p class="truncate font-medium text-mist-100" x-text="$store.viewer.station?.calibrated_on ?? '—'"></p>
            </div>
        </div>
    </div>

    {{-- Live metrics --}}
    <div class="glass glass--panel panel-enter shrink-0 p-4" x-sheen>
        <div class="mb-3 flex items-center justify-between gap-2">
            <h3 class="text-[14px] font-semibold text-white">Data Sensor Terkini</h3>
            <div class="glass glass--inset flex items-center gap-0.5 p-0.5">
                <template x-for="option in ['6h', '24h', '7d', '30d']" :key="option">
                    <button type="button"
                            class="rounded-xl px-2 py-1 text-[10.5px] font-semibold transition"
                            :class="$store.viewer.range === option ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                            @click="$store.viewer.setRange(option)"
                            x-text="option"></button>
                </template>
            </div>
        </div>

        <div class="space-y-2.5">
            <template x-for="metric in ($store.viewer.station?.metrics ?? [])" :key="metric.key">
                <div class="glass glass--inset cursor-pointer p-3 transition"
                     :class="$store.viewer.activeMetric === metric.key && 'ring-1 ring-brand-400/50'"
                     @click="$store.viewer.focusMetric(metric.key)">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-[11.5px] font-medium text-mist-200" x-text="metric.label"></p>
                            <p class="mt-0.5 flex items-baseline gap-1">
                                <span class="tnum text-[20px] leading-none font-bold"
                                      :style="`color:${window.statusColor(metric.status)}`"
                                      x-text="metric.formatted"></span>
                                <span class="text-[10.5px] text-mist-300" x-text="metric.unit"></span>
                            </p>
                        </div>

                        <div class="text-right">
                            <template x-if="metric.trend">
                                <p class="tnum text-[11px] font-semibold"
                                   :class="metric.trend.direction === 'up' ? 'text-state-normal' : (metric.trend.direction === 'down' ? 'text-brand-300' : 'text-mist-300')">
                                    <span x-text="metric.trend.direction === 'up' ? '↑' : (metric.trend.direction === 'down' ? '↓' : '·')"></span>
                                    <span x-text="metric.trend.formatted"></span>
                                </p>
                            </template>
                            <p class="tnum text-[10px] text-mist-400" x-text="metric.recorded_label"></p>
                        </div>
                    </div>

                    <div class="mt-2 h-11 w-full" data-chart x-data="metricSpark(metric.key)"></div>

                    {{-- Threshold ladder --}}
                    <template x-if="metric.thresholds.warning || metric.thresholds.normal_max">
                        <div class="mt-2 flex items-center gap-1.5 text-[9.5px] text-mist-400">
                            <span class="tnum" x-text="'Normal ≤ ' + (metric.thresholds.normal_max ?? '—')"></span>
                            <span class="h-1 flex-1 overflow-hidden rounded-full bg-white/8">
                                <span class="block h-full rounded-full transition-all duration-500"
                                      :style="`width:${Math.min(100, Math.max(4, ((metric.value ?? 0) / ((metric.thresholds.critical ?? metric.thresholds.alert ?? metric.thresholds.warning ?? metric.thresholds.normal_max) || 1)) * 100))}%;background:${window.statusColor(metric.status)}`"></span>
                            </span>
                            <span class="tnum" x-text="'Batas ' + (metric.thresholds.warning ?? metric.thresholds.alert ?? '—')"></span>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="($store.viewer.station?.metrics ?? []).length === 0">
                <p class="rounded-xl bg-white/5 px-3 py-3 text-[12px] text-mist-300">
                    Titik ini adalah panorama orientasi, tidak memiliki kanal sensor.
                </p>
            </template>
        </div>
    </div>

</div>

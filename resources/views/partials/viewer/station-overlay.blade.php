{{-- Chrome shown while the stage sphere holds a station panorama instead of
     the base dam view. It reads the stage component (`twinSphere`), so there is
     only ever one viewer behind it. --}}
<div x-show="$store.viewer.open && !flying" x-cloak x-transition.opacity
     class="pointer-events-none absolute inset-0 z-20">

    {{-- Station banner --}}
    <div class="chrome-scale pointer-events-auto absolute flex flex-wrap items-center gap-2.5"
         style="left: var(--stage-left); top: calc(var(--stage-top) + 8px); right: var(--stage-right)">
        <button type="button" class="glass glass--chip glass-button gap-2 px-3.5 py-2.5 text-[12.5px] font-semibold"
                @click="$store.viewer.close()">
            <x-icon name="arrow-left" class="size-4"/>
            Kembali ke panorama utama
        </button>

        <div class="glass glass--chip flex items-center gap-3 px-4 py-2.5" x-sheen>
            <span class="grid size-8 place-items-center rounded-xl"
                  :style="`background:${window.statusColor($store.viewer.station?.status ?? 'normal')}22;color:${window.statusColor($store.viewer.station?.status ?? 'normal')}`">
                <span x-html="window.iconSvg($store.viewer.station?.type, 16)"></span>
            </span>
            <div class="leading-tight">
                <p class="text-[13px] font-semibold text-white" x-text="$store.viewer.station?.short_name"></p>
                <p class="text-[10.5px] text-mist-300">
                    <span x-text="$store.viewer.station?.type_label"></span>
                    <span class="text-mist-500"> · </span>
                    <span x-text="$store.viewer.station?.zone"></span>
                </p>
            </div>
        </div>

        {{-- Jump to a neighbouring panorama --}}
        <div class="glass glass--chip flex items-center gap-1 p-1.5 max-lg:hidden">
            <template x-for="marker in $store.site.markers.filter(m => m.has_panorama).slice(0, 6)" :key="'jump' + marker.code">
                <button type="button"
                        class="grid size-8 place-items-center rounded-xl transition"
                        :class="$store.viewer.code === marker.code ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:bg-white/10'"
                        :title="marker.name"
                        @click="$store.viewer.open360(marker.code)">
                    <span x-html="window.iconSvg(marker.type, 14)"></span>
                </button>
            </template>
        </div>
    </div>

    {{-- Metric strip along the bottom --}}
    <div class="chrome-scale pointer-events-auto absolute -translate-x-1/2 max-sm:hidden"
         style="bottom: var(--stage-bottom);
                left: calc(var(--stage-left) + (100vw - var(--stage-left) - var(--stage-right)) / 2)">
        <div class="glass glass--chip flex items-center gap-1 p-1.5" x-sheen>
            <template x-for="metric in ($store.viewer.station?.metrics ?? []).slice(0, 5)" :key="'strip' + metric.key">
                <button type="button"
                        class="flex items-center gap-2 rounded-xl px-3.5 py-2 transition"
                        :class="$store.viewer.activeMetric === metric.key ? 'bg-white/12' : 'hover:bg-white/8'"
                        @click="$store.viewer.focusMetric(metric.key)">
                    <span class="status-dot" :style="`background:${window.statusColor(metric.status)}`"></span>
                    <span class="text-left leading-tight">
                        <span class="block text-[10px] text-mist-300" x-text="metric.label"></span>
                        <span class="tnum block text-[12.5px] font-semibold text-white"
                              x-text="metric.formatted + (metric.unit ? ' ' + metric.unit : '')"></span>
                    </span>
                </button>
            </template>

            <template x-if="($store.viewer.station?.metrics ?? []).length === 0">
                <span class="px-3.5 py-2 text-[12px] text-mist-300">Panorama orientasi — tanpa kanal sensor</span>
            </template>
        </div>
    </div>

    {{-- Hotspot detail popover --}}
    <div x-show="activeHotspot" x-cloak x-transition
         class="chrome-scale glass glass--panel pointer-events-auto absolute w-[280px] p-4"
         style="bottom: calc(var(--stage-bottom) + 78px); left: var(--stage-left)">
        <div class="flex items-start justify-between gap-2">
            <p class="text-[13px] font-semibold text-white" x-text="activeHotspot?.label"></p>
            <button type="button" class="text-mist-400 transition hover:text-white" @click="activeHotspot = null">
                <x-icon name="x" class="size-3.5"/>
            </button>
        </div>
        <p class="mt-1.5 text-[11.5px] leading-relaxed text-mist-300" x-text="activeHotspot?.description"></p>
        <template x-if="activeHotspot?.metric_key">
            <div class="mt-2.5 flex items-baseline gap-1.5">
                <span class="tnum text-[20px] font-bold"
                      :style="`color:${window.statusColor($store.viewer.metric(activeHotspot.metric_key)?.status ?? 'normal')}`"
                      x-text="$store.viewer.metric(activeHotspot.metric_key)?.formatted"></span>
                <span class="text-[11px] text-mist-300" x-text="$store.viewer.metric(activeHotspot.metric_key)?.unit"></span>
            </div>
        </template>
    </div>
</div>

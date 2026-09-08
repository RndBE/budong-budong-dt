{{-- Chrome shown while the stage sphere holds a station panorama instead of
     the base dam view. It reads the stage component (`twinSphere`), so there is
     only ever one viewer behind it. --}}
<div x-show="$store.viewer.open && !flying" x-cloak x-transition.opacity
     class="pointer-events-none absolute inset-0 z-20">

    {{-- Station banner. It starts clear of the compass, which keeps its corner
         in both the base view and a station panorama. --}}
    <div class="chrome-scale chrome-slide pointer-events-auto absolute flex flex-wrap items-center gap-2.5"
         style="left: calc(var(--stage-left) + var(--compass-w)); top: calc(var(--stage-top) + 8px);
                right: var(--stage-right)">
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
    </div>

    {{-- Metric strip along the bottom.
         Centred by *spanning* the stage and letting flex do it, not by
         computing the midpoint. Two things were wrong with the midpoint:
         `100vw` counts the scrollbar (the same trap the frame's `w-full`
         avoids), and `chrome-scale` is `zoom`, which multiplies every length
         on the element it sits on — so a left offset of about 1070px came out
         some ninety pixels to the right on a screen scaled to 1.09. Position
         on an unzoomed parent; scale only the plate. --}}
    <div class="chrome-slide pointer-events-none absolute flex justify-center max-sm:hidden"
         style="bottom: var(--stage-bottom); left: var(--stage-left); right: var(--stage-right)">
        <div class="chrome-scale glass glass--chip pointer-events-auto flex items-center gap-1 p-1.5" x-sheen>
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
            <p class="text-[13px] font-semibold text-white"
               x-text="activeStake?.code ?? activeHotspot?.label"></p>
            <button type="button" class="text-mist-400 transition hover:text-white"
                    @click="activeHotspot = null; activeStake = null">
                <x-icon name="x" class="size-3.5"/>
            </button>
        </div>

        {{-- A picked prism answers with its own figure: the linear
             displacement, which is the number the survey is watched by. Its
             components are underneath, because a resultant alone does not say
             whether the movement was sideways or settlement. --}}
        <template x-if="activeStake?.linear !== undefined && activeStake?.linear !== null">
            <div class="mt-2">
                <p class="text-[10px] uppercase tracking-wide text-mist-400">Pergeseran linier</p>
                <p class="tnum text-[30px] font-bold leading-none"
                   {{-- A prism with no reading has no status: plain, not green,
                        which would say the movement was measured and fine. --}}
                   :style="`color:${activeStake.status ? window.statusColor(activeStake.status) : '#e8f4ff'}`">
                    <span x-text="activeStake.formatted"></span><span class="ml-1 text-[15px] font-semibold">mm</span>
                </p>
                <dl class="mt-2 grid grid-cols-3 gap-2 text-[10.5px]">
                    <div>
                        <dt class="text-mist-400">Horizontal</dt>
                        <dd class="tnum font-semibold text-mist-100"><span x-text="activeStake.horizontal_formatted"></span> mm</dd>
                    </div>
                    <div>
                        <dt class="text-mist-400">Vertikal</dt>
                        <dd class="tnum font-semibold text-mist-100"><span x-text="activeStake.vertical_formatted"></span> mm</dd>
                    </div>
                    <div>
                        <dt class="text-mist-400" title="Sudut arah pergeseran pada gambar: 0 derajat = ke kanan, 90 derajat = ke bawah. Bukan azimut hasil survei.">Arah gambar</dt>
                        <dd class="tnum font-semibold text-mist-100"><span x-text="Math.round(activeStake.aspect)"></span>°</dd>
                    </div>
                </dl>
                <button type="button"
                        class="glass glass--inset mt-2.5 flex w-full items-center justify-center gap-2 rounded-xl py-2 text-[11px] font-semibold transition"
                        :class="showVectors ? 'text-brand-300 ring-1 ring-brand-400/50' : 'text-mist-200 hover:text-white'"
                        :aria-pressed="showVectors"
                        @click="toggleVectors()">
                    <x-icon name="deformation" class="size-3.5"/>
                    <span x-text="showVectors ? 'Sembunyikan arah pergeseran' : 'Lihat arah pergeseran'"></span>
                </button>
            </div>
        </template>

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

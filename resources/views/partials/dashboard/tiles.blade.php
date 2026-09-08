<div class="grid grid-cols-4 gap-3.5 max-lg:grid-cols-2">
        <template x-for="tile in ($store.site.dashboard?.primary ?? [])" :key="'kpi' + tile.metric + tile.station">
            <div class="glass glass--panel p-4" x-sheen>
                <p class="text-[11.5px] font-medium text-mist-300" x-text="tile.label"></p>
                <p class="mt-2 flex items-baseline gap-1.5">
                    <span class="tnum text-[28px] leading-none font-extrabold"
                          :style="`color:${window.statusColor(tile.status)}`"
                          x-text="tile.formatted"></span>
                    <span class="text-[12px] text-mist-300" x-text="tile.unit"></span>
                </p>
                <p class="mt-2 flex items-center gap-2 text-[11px] text-mist-400">
                    <template x-if="tile.trend">
                        <span class="font-semibold"
                              :class="tile.trend.direction === 'up' ? 'text-state-normal' : 'text-brand-300'"
                              x-text="(tile.trend.direction === 'up' ? '↑ ' : '↓ ') + tile.trend.formatted"></span>
                    </template>
                    <span class="tnum" x-text="tile.recorded_label ?? tile.note ?? ''"></span>
                </p>
            </div>
        </template>
</div>

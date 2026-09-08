    {{-- Parameter Utama. The dashboard prints the same four tiles across the
         top of the page, so it asks for them to be left out here. --}}
    <div class="glass glass--panel panel-enter shrink-0 p-4" x-sheen>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-[14px] font-semibold text-white">Parameter Utama</h2>
            <a href="{{ route('sensors') }}" class="text-[11px] font-medium text-brand-300 hover:text-brand-200">Lihat Semua</a>
        </div>

        <div class="kpi-grid gap-px overflow-hidden rounded-2xl bg-white/8">
            <template x-for="tile in ($store.site.dashboard?.primary ?? [])" :key="tile.metric + tile.station">
                <div class="bg-ink-900/45 px-2.5 py-3">
                    <p class="mb-1.5 truncate text-[10.5px] font-medium text-mist-300" x-text="tile.label"></p>
                    <p class="flex items-baseline gap-1">
                        <span class="tnum text-[19px] leading-none font-bold"
                              :class="{
                                  'text-white': tile.status === 'normal',
                                  'text-state-waspada': tile.status === 'waspada',
                                  'text-state-siaga': tile.status === 'siaga',
                                  'text-state-bahaya': tile.status === 'bahaya',
                                  'text-state-offline': tile.status === 'offline',
                              }"
                              x-text="tile.formatted"></span>
                        <span class="text-[10px] font-medium text-mist-300" x-text="tile.unit"></span>
                    </p>
                    <p class="mt-1.5 flex items-center gap-1 text-[10px]">
                        <template x-if="tile.trend">
                            <span class="flex items-center gap-0.5 font-semibold"
                                  :class="tile.trend.direction === 'up' ? 'text-state-normal' : (tile.trend.direction === 'down' ? 'text-brand-300' : 'text-mist-300')">
                                <span x-text="tile.trend.direction === 'up' ? '↑' : (tile.trend.direction === 'down' ? '↓' : '·')"></span>
                                <span class="tnum" x-text="tile.trend.formatted"></span>
                            </span>
                        </template>
                        <template x-if="!tile.trend">
                            <span class="tnum text-mist-400" x-text="tile.note ?? tile.recorded_label ?? ''"></span>
                        </template>
                    </p>
                    <p class="tnum mt-0.5 text-[10px] text-mist-400" x-show="tile.trend" x-text="tile.recorded_label"></p>
                </div>
            </template>
        </div>
    </div>

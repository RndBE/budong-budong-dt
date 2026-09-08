        <div class="glass glass--panel p-4" x-sheen
             x-data="trendChart(@js($stationsForChart ?? []))">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-[14px] font-semibold text-white">Tren Muka Air &amp; Debit</h2>
                    <p class="text-[11.5px] text-mist-400">
                        Sumber: <span x-text="stations[0]?.name ?? '—'"></span> · pilih rentang untuk memperbarui
                    </p>
                    {{-- The threshold lines are drawn, but on a reservoir
                         sitting a metre and a half below its limit they are off
                         the top of the picture. The gap in words is the part
                         the shape cannot say. --}}
                    <p class="mt-0.5 text-[11.5px] font-medium text-state-normal" x-show="headroom" x-cloak>
                        <span class="tnum" x-text="headroom?.gap"></span>
                        <span x-text="headroom?.unit"></span>
                        di bawah <span x-text="headroom?.label"></span>
                    </p>
                </div>
                <div class="glass glass--inset flex items-center gap-0.5 p-0.5">
                    <template x-for="option in ['24h', '7d', '30d']" :key="'r' + option">
                        <button type="button" class="rounded-xl px-2.5 py-1 text-[10.5px] font-semibold transition"
                                :class="range === option ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                @click="setRange(option)" x-text="option"></button>
                    </template>
                </div>
            </div>
            <div class="h-[248px] w-full transition-opacity duration-300"
                 :class="loading && 'opacity-40'"
                 data-chart x-ref="chart"></div>
        </div>

    {{-- Riwayat Data Terakhir ------------------------------------------ --}}
    <div class="glass glass--panel panel-enter shrink-0 p-4" x-sheen>
        <div class="mb-2.5 flex items-center justify-between">
            <h2 class="text-[14px] font-semibold text-white">Riwayat Data Terakhir</h2>
            <a href="{{ route('sensors') }}" class="text-[11px] font-medium text-brand-300 hover:text-brand-200">Lihat Semua</a>
        </div>

        <ul class="divide-y divide-white/6">
            <template x-for="row in ($store.site.dashboard?.recent ?? [])" :key="row.station + row.metric">
                <li class="flex items-center gap-2.5 py-2">
                    <span class="grid size-6 shrink-0 place-items-center rounded-lg bg-white/8"
                          :style="`color:${window.statusColor(row.status)}`">
                        <svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M12 3v3M12 18v3M3 12h3M18 12h3"/>
                        </svg>
                    </span>
                    <a :href="`/digital-twin/${row.station}`" class="flex-1 truncate text-[12.5px] text-mist-100 hover:text-white"
                       x-text="row.label"></a>
                    <span class="tnum shrink-0 text-[12.5px] font-semibold"
                          :class="row.show_status_text ? '' : 'text-white'"
                          :style="row.show_status_text ? `color:${window.statusColor(row.status)}` : ''"
                          x-text="row.show_status_text ? row.status_label : row.formatted + (row.unit ? ' ' + row.unit : '')"></span>
                    <span class="tnum w-[62px] shrink-0 text-right text-[11px] text-mist-400" x-text="row.recorded_label"></span>
                </li>
            </template>
        </ul>
    </div>

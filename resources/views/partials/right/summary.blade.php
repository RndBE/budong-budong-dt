{{-- Right panel: site-wide summary. Data comes from the polled `site` store. --}}
<div class="scroll-y flex h-full flex-col gap-3.5 pr-0.5">

    {{-- Parameter Utama. The dashboard prints the same four tiles across the
         top of the page, so it asks for them to be left out here. --}}
    <div class="glass glass--panel panel-enter shrink-0 p-4" x-sheen
         @if ($skipPrimary ?? false) hidden @endif>
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

    {{-- Ringkasan Kesehatan Struktur ----------------------------------- --}}
    <div class="glass glass--panel panel-enter shrink-0 p-4" x-sheen>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-[14px] font-semibold text-white">Ringkasan Kesehatan Struktur</h2>
            <a href="{{ route('analytics') }}" class="text-[11px] font-medium text-brand-300 hover:text-brand-200">Lihat Semua</a>
        </div>

        <div class="flex items-center gap-5">
            <div class="relative size-[122px] shrink-0">
                <svg viewBox="0 0 120 120" class="size-full -rotate-90">
                    <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="11"/>
                    <g x-html="$store.site.healthDonut"></g>
                </svg>
                <div class="absolute inset-0 grid place-items-center">
                    <div class="text-center leading-none">
                        <p class="tnum text-[26px] font-extrabold text-white">
                            <span x-text="$store.site.dashboard?.health?.score ?? '—'"></span><span class="text-[16px]">%</span>
                        </p>
                        <p class="mt-1 text-[11px] font-medium text-mist-300">Sehat</p>
                    </div>
                </div>
            </div>

            <ul class="flex-1 space-y-2">
                <template x-for="bucket in ($store.site.dashboard?.health?.buckets ?? [])" :key="bucket.key">
                    <li class="flex items-center justify-between text-[12.5px]">
                        <span class="flex items-center gap-2 text-mist-200">
                            <span class="status-dot" :style="`background:${bucket.color}`"></span>
                            <span x-text="bucket.label"></span>
                        </span>
                        <span class="tnum font-semibold text-white" x-text="bucket.percent + '%'"></span>
                    </li>
                </template>
            </ul>
        </div>
    </div>

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

    {{-- Peringatan Aktif ----------------------------------------------- --}}
    <div class="glass glass--panel panel-enter shrink-0 p-4" x-sheen>
        <div class="mb-2.5 flex items-center justify-between">
            <h2 class="text-[14px] font-semibold text-white">Peringatan Aktif</h2>
            <a href="{{ route('alerts') }}" class="text-[11px] font-medium text-brand-300 hover:text-brand-200">Lihat Semua</a>
        </div>

        <ul class="space-y-2.5">
            <template x-for="alert in ($store.site.dashboard?.alerts ?? [])" :key="alert.id">
                <li class="flex gap-2.5">
                    <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-xl"
                          :style="`background:${window.statusColor(alert.level)}22;color:${window.statusColor(alert.level)}`">
                        <x-icon name="warning" class="size-4"/>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[12.5px] font-semibold text-white" x-text="alert.title"></p>
                        <p class="truncate text-[11px] text-mist-300" x-text="alert.message"></p>
                        <p class="tnum mt-0.5 text-[10.5px] text-mist-400" x-text="alert.triggered_label"></p>
                    </div>
                    <button type="button" class="self-start rounded-lg px-1.5 py-1 text-mist-400 transition hover:bg-white/8 hover:text-white"
                            title="Tandai selesai"
                            @click="fetch(`/api/alerts/${alert.id}/resolve`, {method:'POST', headers:{'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}}).then(() => $store.site.refreshDashboard())">
                        <x-icon name="check" class="size-3.5"/>
                    </button>
                </li>
            </template>

            <template x-if="($store.site.dashboard?.alerts ?? []).length === 0">
                <li class="flex items-center gap-2 rounded-xl bg-white/5 px-3 py-3 text-[12px] text-mist-300">
                    <x-icon name="check" class="size-4 text-state-normal"/>
                    Tidak ada peringatan aktif.
                </li>
            </template>
        </ul>
    </div>
</div>

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
                            {{-- A count, not a percentage. The score was a
                                 rounded fraction of *parameters* with the
                                 rounding drift absorbed into the largest
                                 bucket — two digits of precision for something
                                 that coarse. --}}
                            <span x-text="$store.site.dashboard?.health?.attention ?? '—'"></span>
                        </p>
                        <p class="mt-1 text-[11px] font-medium text-mist-300"
                           x-text="($store.site.dashboard?.health?.attention ?? 0) === 0 ? 'Semua aman' : 'Perlu dilihat'"></p>
                        <p class="tnum mt-0.5 text-[10px] text-mist-400">
                            dari <span x-text="$store.site.dashboard?.health?.total ?? '—'"></span> parameter
                        </p>
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
                        <span class="tnum font-semibold text-white" x-text="bucket.count"></span>
                    </li>
                </template>
            </ul>
        </div>
    </div>

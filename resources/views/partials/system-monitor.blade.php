@php
    $rows = [
        ['key' => 'network', 'label' => 'Jaringan', 'icon' => 'network'],
        ['key' => 'server', 'label' => 'Server', 'icon' => 'server'],
        ['key' => 'database', 'label' => 'Database', 'icon' => 'database'],
    ];
@endphp

{{-- Foot of the left column, so it is as wide as the menu above it. Where the
     menu carries labels (>= 1440px) it shows the full list; on a narrower rail
     it falls back to a strip of status dots instead of clipping every row. --}}
<div class="glass glass--panel p-3.5 max-[1439px]:p-2" x-sheen>
    <h3 class="mb-2.5 truncate text-[13px] font-semibold text-white max-[1439px]:hidden">Sistem Monitor</h3>

    <ul class="space-y-2 text-[12px] max-[1439px]:hidden">
        @foreach ($rows as $row)
            <li class="flex items-center justify-between gap-2">
                <span class="flex min-w-0 items-center gap-2 text-mist-300">
                    <x-icon :name="$row['icon']" class="size-4 shrink-0 text-mist-400"/>
                    <span class="truncate">{{ $row['label'] }}</span>
                </span>
                <span class="flex shrink-0 items-center gap-1.5 font-medium"
                      :class="($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'text-state-normal' : 'text-state-waspada'">
                    <span class="status-dot"
                          :class="($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'bg-state-normal' : 'bg-state-waspada'"></span>
                    <span x-text="($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'Online' : 'Terganggu'">Online</span>
                </span>
            </li>
        @endforeach

        {{-- Backup carries a date and a time, so it gets its own line rather
             than fighting the label for the width of the column. --}}
        <li class="border-t border-white/8 pt-2">
            <span class="flex min-w-0 items-center gap-2 text-mist-300">
                <x-icon name="save" class="size-4 shrink-0 text-mist-400"/>
                <span class="truncate">Backup terakhir</span>
            </span>
            <span class="tnum mt-0.5 block pl-6 text-mist-200"
                  x-text="$store.site.dashboard?.system?.backup_at ?? '—'">—</span>
        </li>

        <li class="flex items-center justify-between gap-2">
            <span class="flex min-w-0 items-center gap-2 text-mist-300">
                <x-icon name="sensor" class="size-4 shrink-0 text-mist-400"/>
                <span class="truncate">Stasiun aktif</span>
            </span>
            <span class="tnum shrink-0 text-mist-200">
                <span x-text="$store.site.dashboard?.system?.stations_online ?? $store.site.markers.length"></span>/<span
                    x-text="$store.site.dashboard?.system?.stations_total ?? $store.site.markers.length"></span>
            </span>
        </li>
    </ul>

    {{-- Narrow rail: icons only, each carrying its own status colour. --}}
    <ul class="flex flex-col items-center gap-2 min-[1440px]:hidden">
        @foreach ($rows as $row)
            <li class="relative grid size-8 place-items-center rounded-xl bg-white/6"
                :title="'{{ $row['label'] }}: ' + (($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'Online' : 'Terganggu')"
                :class="($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'text-state-normal' : 'text-state-waspada'">
                <x-icon :name="$row['icon']" class="size-4"/>
                <span class="status-dot absolute -right-0.5 -top-0.5"
                      :class="($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'bg-state-normal' : 'bg-state-waspada'"></span>
            </li>
        @endforeach

        <li class="tnum border-t border-white/8 pt-1.5 text-[10.5px] text-mist-300"
            title="Stasiun aktif">
            <span x-text="$store.site.dashboard?.system?.stations_online ?? $store.site.markers.length"></span>/<span
                x-text="$store.site.dashboard?.system?.stations_total ?? $store.site.markers.length"></span>
        </li>
    </ul>
</div>

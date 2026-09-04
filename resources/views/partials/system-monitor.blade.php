@php
    $rows = [
        ['key' => 'network', 'label' => 'Jaringan', 'icon' => 'network'],
        ['key' => 'server', 'label' => 'Server', 'icon' => 'server'],
        ['key' => 'database', 'label' => 'Database', 'icon' => 'database'],
    ];
@endphp

<div class="glass glass--panel p-3.5" x-sheen>
    <h3 class="mb-2.5 text-[13px] font-semibold text-white">Sistem Monitor</h3>

    <ul class="space-y-2 text-[12px]">
        @foreach ($rows as $row)
            <li class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2 text-mist-300">
                    <x-icon :name="$row['icon']" class="size-4 text-mist-400"/>
                    {{ $row['label'] }}
                </span>
                <span class="flex items-center gap-1.5 font-medium"
                      :class="($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'text-state-normal' : 'text-state-waspada'">
                    <span class="status-dot"
                          :class="($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'bg-state-normal' : 'bg-state-waspada'"></span>
                    <span x-text="($store.site.dashboard?.system?.{{ $row['key'] }} ?? 'online') === 'online' ? 'Online' : 'Terganggu'">Online</span>
                </span>
            </li>
        @endforeach

        <li class="flex items-center justify-between gap-2 border-t border-white/8 pt-2">
            <span class="flex items-center gap-2 text-mist-300">
                <x-icon name="save" class="size-4 text-mist-400"/>
                Backup
            </span>
            <span class="tnum text-mist-200" x-text="'Terakhir ' + ($store.site.dashboard?.system?.backup_at ?? '—')">Terakhir —</span>
        </li>

        <li class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-2 text-mist-300">
                <x-icon name="sensor" class="size-4 text-mist-400"/>
                Stasiun Aktif
            </span>
            <span class="tnum text-mist-200">
                <span x-text="$store.site.dashboard?.system?.stations_online ?? $store.site.markers.length"></span>/<span
                    x-text="$store.site.dashboard?.system?.stations_total ?? $store.site.markers.length"></span>
            </span>
        </li>
    </ul>
</div>

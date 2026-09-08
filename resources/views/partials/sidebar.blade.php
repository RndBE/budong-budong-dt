@php
    // `can` hides a destination the signed-in role may not open, so the rail
    // never offers a door that answers 403.
    $items = collect([
        ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['route' => 'twin', 'label' => 'Digital Twin', 'icon' => 'cube'],
        ['route' => 'sensors', 'label' => 'Data Pos', 'icon' => 'sensor'],
        ['route' => 'analytics', 'label' => 'Analisa & Grafik', 'icon' => 'chart-line'],
        ['route' => 'maintenance', 'label' => 'Perawatan', 'icon' => 'wrench', 'can' => 'maintenance.view'],
        ['route' => 'alerts', 'label' => 'Peringatan', 'icon' => 'bell'],
        ['route' => 'reports', 'label' => 'Laporan', 'icon' => 'document'],
        ['route' => 'users', 'label' => 'Pengguna & Akses', 'icon' => 'users', 'can' => 'users.manage'],
        ['route' => 'settings', 'label' => 'Pengaturan', 'icon' => 'cog'],
    ])->filter(fn ($item) => ! isset($item['can']) || auth()->user()?->can($item['can']));
@endphp

{{-- `compact` lives on the app frame: the rail width, the stage inset and
     the system monitor all follow the same flag. --}}
<nav class="glass glass--rail shrink-0 p-2.5 max-sm:p-1.5" x-sheen>
    <ul class="space-y-1 max-sm:flex max-sm:space-y-0 max-sm:overflow-x-auto">
        @foreach ($items as $item)
            @php($active = request()->routeIs($item['route']) || ($item['route'] === 'twin' && request()->routeIs('twin.station')))
            <li class="max-sm:flex-1">
                <a href="{{ route($item['route']) }}"
                   class="nav-item max-sm:min-h-11 max-sm:justify-center max-sm:px-2 {{ $active ? 'nav-item--active' : '' }}"
                   title="{{ $item['label'] }}">
                    <span class="relative shrink-0">
                        <x-icon :name="$item['icon']" class="size-[18px]"/>
                        @if ($item['route'] === 'maintenance')
                            {{-- Unanswered word from the service desk. --}}
                            <span x-show="$store.site.maintenanceUnread > 0" x-cloak
                                  class="absolute -right-1.5 -top-1.5 grid min-w-4 place-items-center rounded-full bg-state-bahaya px-1 text-[9px] font-bold text-white"
                                  x-text="$store.site.maintenanceUnread"></span>
                        @endif
                    </span>
                    <span x-show="!compact" x-cloak class="truncate max-[1439px]:hidden">{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="mt-2 h-px bg-white/8 max-sm:hidden"></div>

    <button type="button"
            class="nav-item mt-1 w-full justify-center text-mist-400 max-[1439px]:hidden"
            @click="toggleRail()"
            :title="compact ? 'Perlebar menu' : 'Ringkas menu'">
        <x-icon name="chevrons-right" class="size-4 transition" ::class="compact ? '' : 'rotate-180'"/>
    </button>
</nav>

@php
    $items = [
        ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['route' => 'twin', 'label' => '3D Digital Twin', 'icon' => 'cube'],
        ['route' => 'sensors', 'label' => 'Data Sensor', 'icon' => 'chart-bar'],
        ['route' => 'analytics', 'label' => 'Analisa & Grafik', 'icon' => 'chart-line'],
        ['route' => 'maintenance', 'label' => 'Perawatan', 'icon' => 'wrench'],
        ['route' => 'alerts', 'label' => 'Peringatan', 'icon' => 'bell'],
        ['route' => 'reports', 'label' => 'Laporan', 'icon' => 'document'],
        ['route' => 'settings', 'label' => 'Pengaturan', 'icon' => 'cog'],
    ];
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
                    <x-icon :name="$item['icon']" class="size-[18px] shrink-0"/>
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

<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Digital Twin') · Bendungan Budong Budong</title>
    <link rel="icon" href="{{ asset('assets/icon/favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full antialiased">

{{-- Turbulence filter that gives the glass its liquid caustics. --}}
<svg width="0" height="0" class="absolute" aria-hidden="true">
    <filter id="lg-warp" x="-20%" y="-20%" width="140%" height="140%">
        <feTurbulence type="fractalNoise" baseFrequency="0.006 0.012" numOctaves="2" seed="7" result="noise">
            <animate attributeName="baseFrequency" dur="26s" keyTimes="0;0.5;1"
                     values="0.006 0.012;0.009 0.008;0.006 0.012" repeatCount="indefinite"/>
        </feTurbulence>
        <feGaussianBlur in="noise" stdDeviation="3.5" result="soft"/>
        <feDisplacementMap in="SourceGraphic" in2="soft" scale="16" xChannelSelector="R" yChannelSelector="G"/>
    </filter>
</svg>

{{-- `w-full`, never `w-screen`: 100vw includes the scrollbar, which pushes
     every right-anchored panel that many pixels off the screen. --}}
<div class="app-frame relative w-full overflow-hidden"
     :class="{
         'app-frame--rail-compact': compact,
         'app-frame--panel-collapsed': panelCollapsed,
     }"
     x-data="{
         panelOpen: false,
         compact: JSON.parse(localStorage.getItem('rail-compact') ?? 'false'),
         panelCollapsed: JSON.parse(localStorage.getItem('panel-collapsed') ?? 'false'),
         togglePanel() {
             this.panelCollapsed = !this.panelCollapsed;
             localStorage.setItem('panel-collapsed', this.panelCollapsed);
             $nextTick(() => window.dispatchEvent(new Event('resize')));
         },
         toggleRail() {
             this.compact = !this.compact;
             localStorage.setItem('rail-compact', this.compact);
             $nextTick(() => window.dispatchEvent(new Event('resize')));
         },
     }"
     x-init="$store.site.start(@js($boot), @js($dashboard ?? null))"
     @keydown.escape.window="panelOpen = false">

    {{-- Stage: time-of-day map render, 3D twin, or the 360 panorama. --}}
    @yield('stage')

    {{-- Chrome: floating glass over the stage. The header sits above the
         summary panel so the user menu can hang over it. --}}
    <header class="chrome-scale pointer-events-none absolute inset-x-0 top-0 z-50"
            data-chrome="header"
            style="padding: var(--gap) var(--gap) 0">
        @include('partials.topbar')
    </header>

    {{-- One left column: menu at the top, system monitor pinned to the
         bottom, both the width of the rail. On phones it flattens into the
         bottom bar and the monitor drops out. --}}
    <aside class="chrome-scale rail-column chrome-slide pointer-events-auto absolute z-30 flex flex-col gap-[var(--gap)]
                  max-sm:!left-[var(--gap)] max-sm:!right-[var(--gap)] max-sm:!top-auto
                  max-sm:!bottom-[var(--gap)] max-sm:!w-auto"
           data-chrome="rail"
           style="left: var(--gap); top: var(--header-h); bottom: var(--gap); width: var(--rail-w)">
        @include('partials.sidebar')

        @hasSection('rail-footer')
            @yield('rail-footer')
        @else
            <div class="mt-auto shrink-0 max-sm:hidden" x-show="!compact" x-cloak>
                @include('partials.system-monitor')
            </div>
        @endif
    </aside>

    {{-- Summary / station panel. Below the xl breakpoint it slides over the
         stage instead of taking a column of its own. --}}
    <section class="chrome-scale pointer-events-auto absolute z-30 max-xl:z-40"
             data-chrome="panel"
             style="right: var(--gap); top: var(--header-h); bottom: var(--gap); width: var(--panel-w-open);
                    container-type: inline-size; container-name: panel;
                    transition: transform 320ms cubic-bezier(.4, 0, .2, 1), opacity 220ms linear"
             {{-- Folded away means out of the tab order too, not just off-screen. --}}
             :inert="panelCollapsed"
             :aria-hidden="panelCollapsed"
             :class="{
                 'max-xl:translate-x-0': panelOpen,
                 'max-xl:translate-x-[calc(100%+28px)]': !panelOpen,
                 'xl:translate-x-[calc(100%+var(--gap))] xl:opacity-0': panelCollapsed,
             }">
        @yield('panel')
    </section>

    <button type="button"
            class="chrome-scale glass glass--chip glass-button pointer-events-auto absolute z-40 hidden size-11 max-xl:grid"
            style="right: var(--gap); top: var(--header-h)"
            :title="panelOpen ? 'Tutup panel data' : 'Buka panel data'"
            :aria-label="panelOpen ? 'Tutup panel data' : 'Buka panel data'"
            :aria-expanded="panelOpen"
            @click="panelOpen = !panelOpen">
        <x-icon name="chart-bar" class="size-[18px]" x-show="!panelOpen"/>
        <x-icon name="x" class="size-[18px]" x-show="panelOpen" x-cloak/>
    </button>

    {{-- Desktop handle for folding the summary panel away. --}}
    <button type="button"
            class="chrome-scale chrome-slide glass glass--chip glass-button pointer-events-auto absolute z-40 hidden h-16 w-7 -translate-y-1/2 xl:grid"
            style="right: calc(var(--panel-w) + var(--gap) * 1.7); top: 50%"
            :title="panelCollapsed ? 'Tampilkan panel data' : 'Sembunyikan panel data'"
            :aria-label="panelCollapsed ? 'Tampilkan panel data' : 'Sembunyikan panel data'"
            :aria-expanded="!panelCollapsed"
            @click="togglePanel()">
        <x-icon name="chevrons-right" class="size-4 transition"
                ::class="panelCollapsed ? 'rotate-180' : ''"/>
    </button>

    @yield('stage-controls')
</div>

</body>
</html>

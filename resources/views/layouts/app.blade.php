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

<div class="app-frame relative w-screen overflow-hidden"
     x-data="{ panelOpen: false }"
     x-init="$store.site.start(@js($boot), @js($dashboard ?? null))">

    {{-- Stage: time-of-day map render, 3D twin, or the 360 panorama. --}}
    @yield('stage')

    {{-- Chrome: floating glass over the stage. --}}
    <header class="chrome-scale pointer-events-none absolute inset-x-0 top-0 z-30"
            data-chrome="header"
            style="padding: var(--gap) var(--gap) 0">
        @include('partials.topbar')
    </header>

    {{-- Side rail on desktop, bottom bar on phones. --}}
    <aside class="chrome-scale pointer-events-auto absolute z-30
                  max-sm:!left-[var(--gap)] max-sm:!right-[var(--gap)] max-sm:!top-auto
                  max-sm:!bottom-[var(--gap)] max-sm:!w-auto"
           data-chrome="rail"
           style="left: var(--gap); top: var(--header-h); width: var(--rail-w)">
        @include('partials.sidebar')
    </aside>

    @hasSection('rail-footer')
        @yield('rail-footer')
    @else
        <div class="chrome-scale pointer-events-auto absolute z-30 max-xl:hidden max-lg:hidden"
             style="left: var(--gap); bottom: var(--gap); width: var(--monitor-w)">
            @include('partials.system-monitor')
        </div>
    @endif

    {{-- Summary / station panel. Below the xl breakpoint it slides over the
         stage instead of taking a column of its own. --}}
    <section class="chrome-scale pointer-events-auto absolute z-30 transition-transform duration-300 max-xl:z-40"
             data-chrome="panel"
             style="right: var(--gap); top: var(--header-h); bottom: var(--gap); width: var(--panel-w);
                    container-type: inline-size; container-name: panel"
             :class="panelOpen ? 'max-xl:translate-x-0' : 'max-xl:translate-x-[calc(100%+28px)]'">
        @yield('panel')
    </section>

    <button type="button"
            class="chrome-scale glass glass--chip glass-button pointer-events-auto absolute z-40 hidden size-11 max-xl:grid"
            style="right: var(--gap); top: var(--header-h)"
            :title="panelOpen ? 'Tutup panel data' : 'Buka panel data'"
            @click="panelOpen = !panelOpen">
        <x-icon name="chart-bar" class="size-[18px]" x-show="!panelOpen"/>
        <x-icon name="x" class="size-[18px]" x-show="panelOpen" x-cloak/>
    </button>

    @yield('stage-controls')
</div>

</body>
</html>

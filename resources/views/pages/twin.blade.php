@extends('layouts.app')

@section('title', '3D Digital Twin')

@section('stage')
    {{-- The stage is the dam's own 360 panorama: station pins live inside the
         sphere, and picking one swaps the sphere to that station's panorama. --}}
    <div class="absolute inset-0"
         x-data="twinSphere()"
         x-init="initSphere()"
         @destroy="destroySphere()"
         @look-at.window="lookAt($event.detail.yaw, $event.detail.pitch)">

        <div class="absolute inset-0"
             :class="{ 'sphere--quiet': !showLabels, 'sphere--placing': editMarkers && !$store.viewer.open }">

            {{-- Photo Sphere Viewer mounts here. The solar grade is applied to
                 the whole canvas so the panorama follows the time of day. --}}
            <div x-ref="sphere"
                 class="sphere-stage absolute inset-0 touch-none select-none"
                 :style="{
                     filter: sphereFilter,
                     transitionProperty: 'filter',
                     transitionDuration: $store.site.sceneTransition,
                     transitionTimingFunction: 'linear',
                 }"></div>

            {{-- Night wash: the panorama itself was shot in daylight, so dusk
                 and night are painted on top of the graded texture. --}}
            <div class="pointer-events-none absolute inset-0"
                 :style="{
                     opacity: nightWash,
                     background: 'linear-gradient(180deg, rgba(6,17,38,.92) 0%, rgba(8,20,42,.72) 45%, rgba(4,11,24,.9) 100%)',
                     transitionProperty: 'opacity',
                     transitionDuration: $store.site.sceneTransition,
                     transitionTimingFunction: 'linear',
                 }"></div>

            {{-- Warm light wash that grows towards dawn/dusk --}}
            <div class="pointer-events-none absolute inset-0 transition-opacity duration-1000"
                 :style="{
                     opacity: $store.site.warmth,
                     background: 'radial-gradient(120% 80% at 78% 8%, rgba(255,163,92,.42), transparent 58%)',
                     mixBlendMode: 'soft-light',
                 }"></div>

            {{-- Depth: darken the edges so the glass panels read clearly --}}
            <div class="pointer-events-none absolute inset-0"
                 style="background:
                    radial-gradient(120% 90% at 50% 42%, transparent 42%, rgba(3,11,20,.52) 100%),
                    linear-gradient(180deg, rgba(3,11,20,.58) 0%, transparent 22%, transparent 76%, rgba(3,11,20,.55) 100%)">
            </div>
        </div>

        {{-- Veil only for the very first sphere: later swaps cross-fade --}}
        <div x-show="loading" x-cloak
             class="absolute inset-0 z-10 grid place-items-center bg-ink-950/45 backdrop-blur-sm">
            <div class="glass glass--panel flex items-center gap-3 px-5 py-3.5">
                <span class="size-4 animate-spin rounded-full border-2 border-brand-300/40 border-t-brand-300"></span>
                <span class="text-[13px] text-mist-100">Memuat panorama 360°…</span>
            </div>
        </div>

        {{-- Station panorama chrome (back button, metrics, hotspot detail) --}}
        @include('partials.viewer.station-overlay')

        {{-- Stage chrome ----------------------------------------------- --}}
        <div class="chrome-scale pointer-events-none absolute z-20"
             style="top: var(--stage-top); bottom: var(--stage-bottom);
                    left: var(--stage-left); right: var(--stage-right)">

            {{-- Compass: the needle follows where the camera looks --}}
            <div class="glass glass--chip pointer-events-auto absolute left-2 top-2 grid size-[62px] place-items-center max-sm:hidden"
                 x-sheen>
                <svg viewBox="0 0 60 60" class="size-11"
                     :style="`transform: rotate(${-heading}deg); transition: transform .12s linear`">
                    <circle cx="30" cy="30" r="26" fill="none" stroke="rgba(255,255,255,.16)"/>
                    <path d="M30 8 33.4 27 30 24 26.6 27Z" fill="#f87171"/>
                    <path d="M30 52 26.6 33 30 36 33.4 33Z" fill="rgba(255,255,255,.55)"/>
                    <text x="30" y="19" text-anchor="middle" font-size="9" fill="#e4eefb" font-family="sans-serif" font-weight="600">N</text>
                </svg>
            </div>

            {{-- Search: picking a result turns the camera to that station --}}
            <div class="pointer-events-auto absolute left-1/2 top-2 w-[290px] -translate-x-1/2
                        max-sm:left-0 max-sm:w-[calc(100%-56px)] max-sm:translate-x-0"
                 x-show="!$store.viewer.open"
                 x-data="{ query: '', open: false }">
                <div class="glass glass--chip flex items-center gap-2.5 px-4 py-2.5" x-sheen>
                    <x-icon name="search" class="size-4 text-mist-300"/>
                    <input type="search" placeholder="Cari Lokasi"
                           class="w-full bg-transparent text-[13px] text-white placeholder:text-mist-300 focus:outline-none"
                           x-model="query" @focus="open = true" @click.outside="open = false">
                </div>

                <div x-show="open && query.length > 0" x-cloak x-transition
                     class="glass glass--panel absolute inset-x-0 top-[52px] max-h-[280px] overflow-y-auto p-1.5">
                    <template x-for="marker in $store.site.markers.filter(m => (m.name + m.code + m.zone).toLowerCase().includes(query.toLowerCase()))"
                              :key="marker.code">
                        <button type="button" class="nav-item w-full text-left"
                                @click="query = ''; open = false; focusMarker(marker)">
                            <span class="status-dot" :style="`background:${window.statusColor(marker.status)}`"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[12.5px] text-mist-100" x-text="marker.name"></span>
                                <span class="block truncate text-[10.5px] text-mist-400" x-text="marker.type_label + ' · ' + (marker.zone ?? '')"></span>
                            </span>
                        </button>
                    </template>
                    <p class="px-3 py-2 text-[11.5px] text-mist-400"
                       x-show="$store.site.markers.filter(m => (m.name + m.code).toLowerCase().includes(query.toLowerCase())).length === 0">
                        Lokasi tidak ditemukan.
                    </p>
                </div>
            </div>

            {{-- Camera controls --}}
            <div class="pointer-events-auto absolute bottom-2 right-2 flex flex-col gap-2">
                <button type="button" class="glass glass--chip glass-button size-11"
                        x-show="!$store.viewer.open"
                        :class="editMarkers && 'text-brand-300 ring-1 ring-brand-400/60'"
                        :title="editMarkers ? 'Selesai atur posisi penanda' : 'Atur posisi penanda'"
                        @click="toggleMarkerEditing()">
                    <x-icon name="map-pin" class="size-[18px]"/>
                </button>

                <button type="button" class="glass glass--chip glass-button size-11"
                        :class="rotating && 'text-brand-300'" title="Putar otomatis" @click="toggleRotate()">
                    <x-icon name="rotate" class="size-[18px]"/>
                </button>

                <button type="button" class="glass glass--chip glass-button size-11" title="Kembali ke arah awal" @click="reset()">
                    <x-icon name="crosshair" class="size-[18px]"/>
                </button>

                <div class="glass glass--chip glass-button flex flex-col overflow-hidden">
                    <button type="button" class="grid size-11 place-items-center transition hover:bg-white/10" title="Perbesar" @click="zoomIn()">
                        <x-icon name="plus" class="size-[18px]"/>
                    </button>
                    <span class="mx-2 h-px bg-white/12"></span>
                    <button type="button" class="grid size-11 place-items-center transition hover:bg-white/10" title="Perkecil" @click="zoomOut()">
                        <x-icon name="minus" class="size-[18px]"/>
                    </button>
                </div>

                <button type="button" class="glass glass--chip glass-button size-11 max-sm:hidden" title="Layar penuh" @click="fullscreen()">
                    <x-icon name="expand" class="size-[18px]"/>
                </button>
            </div>

            {{-- Mode pills --}}
            <div class="pointer-events-auto absolute bottom-2 left-1/2 -translate-x-1/2 max-sm:hidden"
                 x-show="!$store.viewer.open"
                 x-data="{ mode: 'sensor' }">
                <div class="glass glass--chip flex items-center gap-1 p-1.5" x-sheen>
                    @php
                        $modes = [
                            ['key' => 'sensor', 'label' => 'Lokasi Sensor', 'icon' => 'map-pin'],
                            ['key' => 'air', 'label' => 'Ukuran Air', 'icon' => 'waves'],
                        ];
                    @endphp
                    @foreach ($modes as $mode)
                        <button type="button"
                                class="flex items-center gap-2 rounded-xl px-4 py-2 text-[12.5px] font-semibold transition"
                                :class="mode === '{{ $mode['key'] }}'
                                    ? 'bg-brand-500/90 text-white shadow-[0_10px_24px_-12px_rgba(31,139,245,.9)]'
                                    : 'text-mist-200 hover:bg-white/8'"
                                @click="mode = '{{ $mode['key'] }}'; $dispatch('map-mode', '{{ $mode['key'] }}')">
                            <x-icon :name="$mode['icon']" class="size-4"/>
                            {{ $mode['label'] }}
                        </button>
                    @endforeach

                    <span class="mx-0.5 h-6 w-px bg-white/12"></span>

                    {{-- Independent of the modes above: show or hide the caption
                         next to every pin. --}}
                    <button type="button"
                            class="flex items-center gap-2 rounded-xl px-4 py-2 text-[12.5px] font-semibold transition"
                            :class="showLabels
                                ? 'bg-brand-500/90 text-white shadow-[0_10px_24px_-12px_rgba(31,139,245,.9)]'
                                : 'text-mist-200 hover:bg-white/8'"
                            :title="showLabels ? 'Sembunyikan label penanda' : 'Tampilkan label penanda'"
                            @click="toggleLabels()">
                        <x-icon name="tag" class="size-4"/>
                        Label
                    </button>
                </div>
            </div>

            {{-- Pin placement hint --}}
            <div class="pointer-events-none absolute left-1/2 bottom-14 -translate-x-1/2"
                 x-show="editMarkers && !$store.viewer.open" x-cloak>
                <span class="glass glass--chip glass--flat px-3.5 py-2 text-[11px] font-medium text-mist-100">
                    Seret penanda ke titik aslinya di panorama — tersimpan otomatis.
                </span>
            </div>

            {{-- Zoom readout --}}
            <div class="pointer-events-none absolute bottom-2 left-2 max-sm:hidden">
                <span class="glass glass--chip glass--flat px-3 py-1.5 text-[11px] font-semibold text-mist-200">
                    <span class="tnum" x-text="Math.round(zoom * 100) + '%'"></span>
                </span>
            </div>

            {{-- Time control: follow the real clock, scrub a day, or play it back
                 faster than real time. Hidden where the panel toggle sits. --}}
            <div class="pointer-events-auto absolute right-2 top-2 max-xl:hidden"
                 x-data="{ open: false }" @click.outside="open = false">

                <button type="button"
                        class="glass glass--chip flex items-center gap-1.5 px-3 py-2 text-[11px] font-semibold text-mist-200 transition hover:brightness-125"
                        @click="open = !open; if (open) $store.site.loadCurve()">
                    <x-icon name="clock" class="size-3.5"
                            ::class="$store.site.clock.simulated ? 'text-amber-300' : 'text-brand-300'"/>
                    <span x-text="$store.site.scene.phase_label ?? $store.site.environment?.sun?.phase_label ?? ''"></span>
                    <span class="text-mist-400">·</span>
                    <span class="tnum" x-text="$store.site.clock.time.slice(0, 5)"></span>
                    <span x-show="$store.site.clock.simulated" x-cloak
                          class="rounded-md bg-amber-400/20 px-1.5 py-0.5 text-[9.5px] text-amber-200">
                        simulasi
                    </span>
                </button>

                <div x-show="open" x-cloak x-transition.origin.top.right
                     class="glass glass--panel absolute right-0 mt-2 w-[268px] p-3.5">

                    <div class="flex items-center justify-between">
                        <span class="tnum text-[19px] font-bold text-white" x-text="$store.site.clock.time.slice(0, 5)"></span>

                        <div class="glass glass--inset flex items-center gap-0.5 p-0.5">
                            <button type="button" class="rounded-lg px-2 py-1 text-[10.5px] font-semibold transition"
                                    :class="$store.site.time.mode === 'realtime' ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                    title="Ikuti jam asli lokasi"
                                    @click="$store.site.useRealtime()">Otomatis</button>
                            <button type="button" class="rounded-lg px-2 py-1 text-[10.5px] font-semibold transition"
                                    :class="$store.site.time.mode === 'custom' ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                    title="Pakai waktu simulasi"
                                    @click="$store.site.useCustomTime()">Kustom</button>
                        </div>
                    </div>

                    {{-- Scrubbing the slider is what switches the stage to simulated time. --}}
                    <input type="range" min="0" max="1439" step="5"
                           class="mt-2 w-full accent-brand-500"
                           :value="Math.floor($store.site.time.minute)"
                           @input="$store.site.setMinute($event.target.value)">

                    <div class="mt-1 flex items-center justify-between text-[9.5px] text-mist-400">
                        <span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>24:00</span>
                    </div>

                    <div class="mt-2.5 flex items-center gap-1.5">
                        <button type="button" class="glass glass--inset grid size-9 shrink-0 place-items-center transition hover:bg-white/10"
                                :title="$store.site.time.playing ? 'Jeda' : 'Jalankan'"
                                @click="$store.site.togglePlay()">
                            <x-icon name="pause" class="size-4" x-show="$store.site.time.playing"/>
                            <x-icon name="play" class="size-4" x-show="!$store.site.time.playing"/>
                        </button>

                        {{-- Labelled by how much faster than real time they run. --}}
                        <template x-for="option in [[1, '60×'], [5, '300×'], [15, '900×'], [60, '3600×']]" :key="option[0]">
                            <button type="button"
                                    class="glass glass--inset flex-1 rounded-xl py-2 text-[10.5px] font-semibold transition"
                                    :class="$store.site.time.speed === option[0] ? 'text-brand-300 ring-1 ring-brand-400/50' : 'text-mist-300 hover:text-white'"
                                    @click="$store.site.setSpeed(option[0])"
                                    x-text="option[1]"></button>
                        </template>
                    </div>

                    <p class="tnum mt-1.5 text-[9.5px] text-mist-400" x-text="$store.site.speedLabel"></p>
                </div>
            </div>
        </div>

        {{-- Deep link: /digital-twin/{station} opens that panorama straight away --}}
        @if ($openStation)
            <div x-init="$nextTick(() => $store.viewer.open360(@js($openStation)))"></div>
        @endif
    </div>
@endsection

@section('panel')
    <div x-show="!$store.viewer.open" class="h-full">
        @include('partials.right.summary')
    </div>
    @include('partials.right.station')
@endsection

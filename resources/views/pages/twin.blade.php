@extends('layouts.app')

@section('title', 'Digital Twin')

@section('stage')
    {{-- The stage is the dam's own 360 panorama: station pins live inside the
         sphere, and picking one swaps the sphere to that station's panorama. --}}
    <div class="absolute inset-0"
         x-data="twinSphere()"
         x-init="initSphere()"
         @destroy="destroySphere()"
         @look-at.window="lookAt($event.detail.yaw, $event.detail.pitch)"
         @keydown.escape.window="$store.viewer.open && $store.viewer.close()"
         @focus-station.window="focusMarker($store.site.markerByCode($event.detail))">

        <div class="absolute inset-0"
             :class="{
                 'sphere--quiet': !showLabels,
                 'sphere--placing': editMarkers && !$store.viewer.open,
                 'sphere--departing': departing,
             }">

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
        <div class="chrome-scale chrome-slide pointer-events-none absolute z-20"
             style="top: var(--stage-top); bottom: var(--stage-bottom);
                    left: var(--stage-left); right: var(--stage-right)">

            {{-- Compass: the case and its top marker stay put — that marker is
                 where the camera looks. The dial with the cardinal points turns
                 underneath it, so the red needle always shows north. --}}
            <button type="button"
                    class="glass glass--chip glass-button pointer-events-auto absolute left-2 top-2 flex flex-col items-center gap-0.5 px-2 py-1.5 max-sm:hidden"
                    x-sheen
                    :title="'Arah pandang ' + compassLabel + ' — klik untuk menghadap utara'"
                    :aria-label="'Arah pandang ' + compassLabel + '. Klik untuk menghadap utara'"
                    @click="faceNorth()">
                <svg viewBox="0 0 60 60" class="size-[50px]">
                    <circle cx="30" cy="30" r="27" fill="rgba(4,12,22,.32)" stroke="rgba(255,255,255,.16)"/>

                    {{-- Fixed: the direction the camera faces. --}}
                    <path d="M30 1 34.2 8 25.8 8Z" fill="#47a6ff"/>

                    {{-- Only north is spelled out; at 50px the other three read
                         better as ticks than as letters. --}}
                    <g :style="`transform: rotate(${-dialAngle}deg); transform-origin: 30px 30px; transition: transform .12s linear`">
                        <path d="M30 10 33.6 30 30 26.6 26.4 30Z" fill="#f87171"/>
                        <path d="M30 50 26.4 30 30 33.4 33.6 30Z" fill="rgba(255,255,255,.45)"/>
                        <circle cx="30" cy="30" r="2.2" fill="rgba(255,255,255,.8)"/>

                        <text x="30" y="20" text-anchor="middle" font-size="10" font-weight="700"
                              fill="#fca5a5" font-family="sans-serif">U</text>

                        <g stroke="rgba(228,238,251,.5)" stroke-width="1.4" stroke-linecap="round">
                            <line x1="49" y1="30" x2="45" y2="30"/>
                            <line x1="30" y1="49" x2="30" y2="45"/>
                            <line x1="11" y1="30" x2="15" y2="30"/>
                        </g>
                    </g>
                </svg>

                <span class="tnum text-[10px] font-semibold text-mist-200" x-text="compassLabel">0° U</span>
            </button>

            {{-- Camera controls --}}
            <div class="pointer-events-auto absolute bottom-2 right-2 flex flex-col gap-2">
                {{-- Placing pins writes to the station record, so it follows
                     the `stations.move` ability. --}}
                @can('stations.move')
                <button type="button" class="glass glass--chip glass-button size-11"
                        x-show="!$store.viewer.open"
                        :class="editMarkers && 'text-brand-300 ring-1 ring-brand-400/60'"
                        :title="editMarkers ? 'Selesai atur posisi penanda' : 'Atur posisi penanda'"
                        :aria-label="editMarkers ? 'Selesai atur posisi penanda' : 'Atur posisi penanda'"
                        :aria-pressed="editMarkers"
                        @click="toggleMarkerEditing()">
                    <x-icon name="map-pin" class="size-[18px]"/>
                </button>
                @endcan

                <button type="button" class="glass glass--chip glass-button size-11"
                        :class="rotating && 'text-brand-300'" title="Putar otomatis" aria-label="Putar otomatis" @click="toggleRotate()">
                    <x-icon name="rotate" class="size-[18px]"/>
                </button>

                <button type="button" class="glass glass--chip glass-button size-11" title="Kembali ke arah awal" aria-label="Kembali ke arah awal" @click="reset()">
                    <x-icon name="crosshair" class="size-[18px]"/>
                </button>

                {{-- One stepper, not two buttons: the divider runs edge to edge
                     and each half is 40px tall — still a comfortable target,
                     without the airy gap around the glyphs. --}}
                <div class="glass glass--chip flex w-11 flex-col overflow-hidden text-mist-100">
                    <button type="button" class="grid h-10 w-full place-items-center transition hover:bg-white/10" title="Perbesar" aria-label="Perbesar" @click="zoomIn()">
                        <x-icon name="plus" class="size-[18px]"/>
                    </button>
                    <span class="h-px w-full bg-white/12"></span>
                    <button type="button" class="grid h-10 w-full place-items-center transition hover:bg-white/10" title="Perkecil" aria-label="Perkecil" @click="zoomOut()">
                        <x-icon name="minus" class="size-[18px]"/>
                    </button>
                </div>

                <button type="button" class="glass glass--chip glass-button size-11 max-sm:hidden" title="Layar penuh" aria-label="Layar penuh" @click="fullscreen()">
                    <x-icon name="expand" class="size-[18px]"/>
                </button>
            </div>

            {{-- Mode pills --}}
            <div class="pointer-events-auto absolute bottom-2 left-1/2 -translate-x-1/2 max-sm:hidden"
                 x-show="!$store.viewer.open">
                <div class="glass glass--chip flex items-center gap-1 p-1.5" x-sheen
                     role="group" aria-label="Penanda yang ditampilkan">
                    @php
                        $modes = [
                            ['key' => 'sensor', 'label' => 'Lokasi Sensor', 'icon' => 'map-pin', 'hint' => 'Tampilkan semua stasiun'],
                        ];
                    @endphp
                    @foreach ($modes as $mode)
                        <button type="button"
                                class="flex items-center gap-2 rounded-xl px-4 py-2 text-[12.5px] font-semibold transition"
                                title="{{ $mode['hint'] }}"
                                :aria-pressed="pinFilter === '{{ $mode['key'] }}'"
                                :class="pinFilter === '{{ $mode['key'] }}'
                                    ? 'bg-brand-500/90 text-white shadow-[0_10px_24px_-12px_rgba(31,139,245,.9)]'
                                    : 'text-mist-200 hover:bg-white/8'"
                                @click="setPinFilter('{{ $mode['key'] }}')">
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
                            :aria-pressed="showLabels"
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

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
                 'sphere--placing': editMarkers,
                 'sphere--vectors': showVectors,
                 {{-- Close enough that a figure per stake still has room. --}}
                 'sphere--near': zoom >= 0.34 || showFigures,
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

            {{-- Cloud and rain, from the weather station or the scenario. --}}
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                @include('partials.sky-layers')
            </div>

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
                 underneath it, so the red needle always shows north.

                 Flush with the top of the stage, which is the line the rail
                 and the summary panel both start on: the three of them are the
                 same row of chrome and should read as one. --}}
            <button type="button"
                    class="glass glass--chip glass-button pointer-events-auto absolute left-2 top-0 flex flex-col items-center gap-0.5 px-2 py-1.5 max-sm:hidden"
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
                {{-- Folding the summary panel away. It belongs in this stack
                     rather than floating beside it: parked at the middle of
                     the screen it landed on whichever control happened to be
                     at the top of the column, and a control that has to dodge
                     another one is in the wrong place. Only on `xl`, where the
                     panel has a column of its own to give back. --}}
                <button type="button"
                        class="glass glass--chip glass-button hidden size-11 xl:grid"
                        :title="panelCollapsed ? 'Tampilkan panel data' : 'Sembunyikan panel data'"
                        :aria-label="panelCollapsed ? 'Tampilkan panel data' : 'Sembunyikan panel data'"
                        :aria-expanded="!panelCollapsed"
                        @click="togglePanel()">
                    <x-icon name="chevrons-right" class="size-4 transition"
                            ::class="panelCollapsed ? 'rotate-180' : ''"/>
                </button>

                {{-- One control for both views: station pins on the base
                     panorama, that station's own hotspots inside it. Both
                     write to a record, so both follow `stations.move`. --}}
                @can('stations.move')
                <button type="button" class="glass glass--chip glass-button size-11"
                        :class="editMarkers && 'text-brand-300 ring-1 ring-brand-400/60'"
                        :title="placeLabel"
                        :aria-label="placeLabel"
                        :aria-pressed="editMarkers"
                        @click="toggleMarkerEditing()">
                    <x-icon name="map-pin" class="size-[18px]"/>
                </button>
                @endcan

                {{-- Which way each prism has moved. Only offered where there
                     are prisms to point: the arrows would be an empty promise
                     on a panorama that carries none. --}}
                <button type="button" class="glass glass--chip glass-button size-11"
                        x-show="$store.viewer.open && hasStakes" x-cloak
                        :class="showVectors && 'text-brand-300 ring-1 ring-brand-400/60'"
                        :title="showVectors ? 'Sembunyikan arah pergeseran' : 'Tampilkan arah pergeseran patok'"
                        :aria-label="showVectors ? 'Sembunyikan arah pergeseran' : 'Tampilkan arah pergeseran patok'"
                        :aria-pressed="showVectors"
                        @click="toggleVectors()">
                    <x-icon name="deformation" class="size-[18px]"/>
                </button>

                {{-- The figures those arrows are the length of. They arrive
                     with the zoom that makes room for them; this holds them
                     open at any zoom, which is the reader's call because the
                     far end of a line will overlap. --}}
                <button type="button" class="glass glass--chip glass-button size-11"
                        x-show="$store.viewer.open && hasStakes" x-cloak
                        :class="showFigures && 'text-brand-300 ring-1 ring-brand-400/60'"
                        :title="showFigures ? 'Sembunyikan besar pergeseran' : 'Tampilkan besar pergeseran tiap patok'"
                        :aria-label="showFigures ? 'Sembunyikan besar pergeseran' : 'Tampilkan besar pergeseran tiap patok'"
                        :aria-pressed="showFigures"
                        @click="toggleFigures()">
                    <x-icon name="tag" class="size-[18px]"/>
                </button>

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

            {{-- Placement hint. Inside a station it has to clear the metric
                 strip, which sits on the stage floor.

                 The offset is bound as a *class*, not a style: `x-show` hides
                 an element by writing `display` into its style attribute, and
                 a `:style` binding on the same element rewrites that attribute
                 whole — which put this hint on screen with placement off. --}}
            <div class="pointer-events-none absolute left-1/2 -translate-x-1/2"
                 x-show="editMarkers" x-cloak
                 :class="$store.viewer.open ? 'bottom-[calc(var(--stage-bottom)+78px)]' : 'bottom-14'">
                <span class="glass glass--chip glass--flat px-3.5 py-2 text-[11px] font-medium text-mist-100"
                      x-text="placeHint"></span>
            </div>

            {{-- An arrow is a length, so the scale it was drawn at has to be
                 on screen with it. --}}
            <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-14"
                 x-show="showVectors && $store.viewer.open && !editMarkers" x-cloak>
                <span class="glass glass--chip glass--flat px-3.5 py-2 text-[11px] font-medium text-mist-100">
                    Panah = arah pergeseran pada gambar · panjangnya
                    <span class="tnum" x-text="vectorScale.toLocaleString('id-ID')"></span> px per mm
                </span>
            </div>

            {{-- Zoom readout --}}
            <div class="pointer-events-none absolute bottom-2 left-2 max-sm:hidden">
                <span class="glass glass--chip glass--flat px-3 py-1.5 text-[11px] font-semibold text-mist-200">
                    <span class="tnum" x-text="Math.round(zoom * 100) + '%'"></span>
                </span>
            </div>

            {{-- Top right of the stage: what the sky is doing, and — on a
                 station that has gates — how to work them. --}}
            <div class="pointer-events-auto absolute right-2 top-2 flex items-start gap-2">

                {{-- Working the spillway. It sits with the stage's own chrome
                     rather than on the bottom bar because it belongs to one
                     station, not to the stage; and it opens a dialog rather
                     than a panel section, being the one control in the app
                     that writes an order to a structure. --}}
                <button type="button" class="glass glass--chip glass-button gap-2 px-3 py-2 text-[11px] font-semibold"
                        x-show="$store.viewer.open && $store.viewer.gates.length" x-cloak
                        :class="$store.viewer.gatesOpen ? 'text-brand-300 ring-1 ring-brand-400/60' : 'text-mist-200'"
                        title="Kontrol pintu spillway" aria-label="Kontrol pintu spillway"
                        :aria-pressed="$store.viewer.gatesOpen"
                        @click="$store.viewer.openGates()">
                    <x-icon name="gate" class="size-4"/>
                    <span class="max-sm:hidden">Kontrol Pintu</span>
                </button>

            {{-- Time control: follow the real clock, scrub a day, or play it back
                 faster than real time. Hidden where the panel toggle sits. --}}
            <div class="relative max-xl:hidden"
                 x-data="{ open: false }" @click.outside="open = false">

                <button type="button"
                        class="glass glass--chip flex items-center gap-1.5 px-3 py-2 text-[11px] font-semibold text-mist-200 transition hover:brightness-125"
                        @click="open = !open; if (open) $store.site.loadCurve()">
                    <x-icon name="clock" class="size-3.5"
                            ::class="$store.site.clock.simulated ? 'text-amber-300' : 'text-brand-300'"/>
                    <span x-text="$store.site.scene.phase_label ?? $store.site.environment?.sun?.phase_label ?? ''"></span>
                    <span class="text-mist-400">·</span>
                    <span x-text="$store.site.sky.label"></span>
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

                    {{-- Skenario langit: a what-if, next to the other one. The
                         reading it replaces is printed underneath so the two are
                         never confused. --}}
                    <div class="mt-3 border-t border-white/10 pt-3">
                        <div class="mb-1.5 flex items-center justify-between">
                            <span class="text-[10.5px] font-semibold text-mist-200">Skenario langit</span>
                            <span x-show="$store.site.sky.simulated" x-cloak
                                  class="rounded-md bg-amber-400/20 px-1.5 py-0.5 text-[9.5px] text-amber-200">
                                simulasi
                            </span>
                        </div>

                        <div class="grid grid-cols-3 gap-1">
                            <button type="button"
                                    class="glass glass--inset rounded-lg py-1.5 text-[10px] font-semibold transition"
                                    :class="$store.site.skyScenario === 'auto' ? 'text-brand-300 ring-1 ring-brand-400/50' : 'text-mist-300 hover:text-white'"
                                    title="Ikuti iluminasi dan penakar hujan"
                                    @click="$store.site.setSkyScenario('auto')">Otomatis</button>

                            <template x-for="code in ['cerah', 'berawan', 'mendung', 'rintik', 'hujan']" :key="'sky' + code">
                                <button type="button"
                                        class="glass glass--inset rounded-lg py-1.5 text-[10px] font-semibold capitalize transition"
                                        :class="$store.site.skyScenario === code ? 'text-brand-300 ring-1 ring-brand-400/50' : 'text-mist-300 hover:text-white'"
                                        @click="$store.site.setSkyScenario(code)"
                                        x-text="code === 'rintik' ? 'Rintik' : code"></button>
                            </template>
                        </div>

                        <p class="mt-1.5 text-[9.5px] leading-relaxed text-mist-400"
                           x-text="$store.site.sky.reason"></p>
                    </div>
                </div>
            </div>
            </div>
        </div>


        {{-- Gate control. Teleported, because the stage sits in a transformed
             stacking context and a fixed scrim declared inside it is trapped
             under the header however high its z-index climbs. --}}
        <template x-teleport="body">
            <div x-show="$store.viewer.gatesOpen" x-cloak x-transition.opacity.duration.150ms
                 class="modal-scrim" @click.self="$store.viewer.closeGates()"
                 @keydown.escape.window="$store.viewer.gatesOpen && $store.viewer.closeGates()">
                <div class="modal-card modal-card--narrow glass glass--panel glass--menu p-4"
                     x-show="$store.viewer.gatesOpen" x-transition
                     role="dialog" aria-modal="true" aria-labelledby="gate-dialog-title"
                     x-data="{ draft: {} }">
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div>
                            <h3 id="gate-dialog-title" class="text-[15px] font-semibold text-white">Kontrol Pintu Spillway</h3>
                            <p class="mt-0.5 text-[11px] text-mist-400">
                                Bukaan adalah posisi daun saat ini dalam sentimeter; target adalah
                                perintah terakhir. Daun bergerak beberapa menit sebelum keduanya bertemu.
                            </p>
                        </div>
                        <button type="button" class="glass glass--chip glass-button size-9 shrink-0"
                                title="Tutup" aria-label="Tutup kontrol pintu"
                                @click="$store.viewer.closeGates()">
                            <x-icon name="x" class="size-4"/>
                        </button>
                    </div>

                    <div class="scroll-y max-h-[min(64dvh,520px)] pr-0.5">
                        <template x-for="leaf in $store.viewer.gates" :key="leaf.id">
                            <div class="glass glass--inset mb-2 p-3 last:mb-0"
                                 :class="$store.viewer.gate === leaf.meta?.gate && 'ring-1 ring-brand-400/60'">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-[12.5px] font-semibold text-white" x-text="leaf.label"></p>
                                    <p class="tnum text-[11px] text-mist-300">
                                        Target
                                        <span class="font-semibold text-mist-100"
                                              x-text="$store.viewer.gateMetric(leaf, 'target')?.formatted ?? '—'"></span> cm
                                    </p>
                                </div>

                                <p class="tnum mt-0.5 text-[19px] leading-tight font-bold"
                                   :style="`color:${window.statusColor($store.viewer.gateMetric(leaf)?.status ?? 'normal')}`">
                                    <span x-text="$store.viewer.gateMetric(leaf)?.formatted ?? '—'"></span><span class="ml-1 text-[13px] font-semibold">cm</span>
                                    <template x-if="$store.viewer.gatePercent(leaf) !== null">
                                        <span class="ml-1.5 text-[12px] font-semibold text-mist-300">
                                            · <span x-text="$store.viewer.gatePercent(leaf)"></span> %
                                        </span>
                                    </template>
                                </p>

                                @can('gates.control')
                                    {{-- Wraps rather than squeezing: below the
                                         slider's own minimum the number field
                                         and the button would be crushed, and
                                         this is the control that opens a
                                         spillway. --}}
                                    <div class="mt-2.5 flex flex-wrap items-center gap-2">
                                        <input type="range" min="0" step="1" class="min-w-[140px] flex-1"
                                               :max="$store.viewer.gateStroke(leaf)"
                                               :aria-label="'Bukaan ' + leaf.label + ' dalam sentimeter'"
                                               :value="draft[leaf.id] ?? Math.round($store.viewer.gateMetric(leaf, 'target')?.value ?? 0)"
                                               @input="draft[leaf.id] = Number($event.target.value)">

                                        <label class="glass glass--inset flex w-[78px] items-center gap-1 px-2 py-1.5">
                                            <input type="number" min="0" step="1"
                                                   class="tnum w-full min-w-0 bg-transparent text-right text-[12px] text-white outline-none"
                                                   :max="$store.viewer.gateStroke(leaf)"
                                                   :aria-label="'Bukaan ' + leaf.label + ' dalam sentimeter'"
                                                   :value="draft[leaf.id] ?? Math.round($store.viewer.gateMetric(leaf, 'target')?.value ?? 0)"
                                                   @input="draft[leaf.id] = Number($event.target.value)">
                                            <span class="text-[11px] text-mist-400">cm</span>
                                        </label>

                                        <button type="button"
                                                class="glass glass--chip glass-button min-h-10 px-3 text-[11.5px] font-semibold text-white"
                                                :disabled="$store.viewer.gateBusy === leaf.meta?.gate"
                                                @click="$store.viewer.orderGate(leaf, draft[leaf.id] ?? Math.round($store.viewer.gateMetric(leaf, 'target')?.value ?? 0))">
                                            <span x-text="$store.viewer.gateBusy === leaf.meta?.gate ? 'Mengirim…' : 'Terapkan'"></span>
                                        </button>
                                    </div>

                                    <p class="mt-1.5 text-[10.5px] text-mist-400">
                                        Langkah penuh <span class="tnum" x-text="$store.viewer.gateStroke(leaf)"></span> cm ·
                                        perintah <span class="tnum" x-text="draft[leaf.id] ?? Math.round($store.viewer.gateMetric(leaf, 'target')?.value ?? 0)"></span> cm
                                        = <span class="tnum" x-text="Math.round((draft[leaf.id] ?? Math.round($store.viewer.gateMetric(leaf, 'target')?.value ?? 0)) / $store.viewer.gateStroke(leaf) * 100)"></span> %
                                    </p>
                                @endcan
                            </div>
                        </template>
                    </div>

                    <p class="mt-2 text-[11px] text-state-bahaya" x-show="$store.viewer.gateError" x-cloak
                       x-text="$store.viewer.gateError"></p>

                    @cannot('gates.control')
                        <p class="mt-2 text-[11px] text-mist-400">Perannya tidak mencakup pengaturan bukaan pintu.</p>
                    @endcannot
                </div>
            </div>
        </template>

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

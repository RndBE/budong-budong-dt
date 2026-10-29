@php($stationSearch = request()->routeIs('twin', 'twin.station'))

<div class="flex items-start gap-4 max-lg:gap-2">

    {{-- Identity.

         The name stays on one line and ellipsises. The digital twin is the
         only page that puts a search field in this row; the name used to give
         that field its width and wrap onto two, and because the header is
         sized by its content that alone made the twin's header some fifty
         pixels taller than every other page — the whole top of the screen read
         as spaced further apart there, which is not a thing a page should
         differ in. It may not be `shrink-0` either: held at its full width the
         row overflows and the account menu walks off the right edge by 900px.
         So it shrinks, and truncates rather than wrapping. --}}
    <div class="pointer-events-auto flex min-w-0 items-center gap-3 pl-1 pr-2">
        <img src="{{ asset('assets/logopu.png') }}" alt="Logo Kementerian Pekerjaan Umum"
             class="size-11 shrink-0 rounded-2xl object-cover shadow-[0_14px_30px_-14px_rgba(2,8,20,.9)]">
        {{-- A floor, so the name shrinks to something rather than to nothing:
             this row is already wider than the screen below about 1100px with
             the search in it, and a header whose title has vanished entirely
             is worse than one that is crowded. --}}
        <div class="min-w-[6.5rem] leading-tight">
            <h1 class="truncate text-[19px] font-extrabold tracking-tight text-white uppercase max-xl:text-[16px] max-md:text-[14px] max-sm:text-[13px]">Bendungan Budong Budong</h1>
            <p class="text-[12px] font-semibold tracking-wide text-mist-200/90 uppercase max-sm:text-[10px]">BWS Sulawesi V</p>
            <p class="mt-0.5 flex items-center gap-1.5 text-[10px] font-semibold tracking-[.14em] text-brand-300/90 uppercase max-xl:hidden">
                <span class="status-dot bg-state-normal"></span>
                Digital Twin &amp; Dam Monitoring System
            </p>
        </div>
    </div>

    <div class="flex min-w-0 flex-1 items-center justify-center gap-3 max-lg:gap-2 max-sm:hidden">

        {{-- Weather --}}
        <div class="glass glass--chip pointer-events-auto flex items-center gap-3 px-4 py-2.5 max-lg:hidden" x-sheen>
            <template x-if="$store.site.environment">
                <div class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-xl bg-white/8 text-brand-200">
                        <template x-if="$store.site.scene.phase === 'night'">
                            <x-icon name="moon" class="size-5 text-mist-100"/>
                        </template>
                        <template x-if="$store.site.scene.phase !== 'night'">
                            <x-icon name="cloud-sun" class="size-5 text-amber-200"/>
                        </template>
                    </span>
                    <div class="leading-tight">
                        <div class="flex items-baseline gap-2">
                            <span class="tnum text-[22px] font-bold text-white"
                                  x-text="$store.site.environment.weather.temperature_c.toLocaleString('id-ID', {minimumFractionDigits:1, maximumFractionDigits:1}) + '°C'"></span>
                            <span class="text-[13px] font-medium text-mist-200"
                                  x-text="$store.site.environment.weather.condition_label"></span>
                        </div>
                        <p class="tnum text-[11px] text-mist-300 max-2xl:hidden">
                            Angin <span x-text="$store.site.environment.weather.wind_kmh"></span> km/h
                            <span class="px-1.5 text-mist-400">·</span>
                            RH <span x-text="$store.site.environment.weather.humidity"></span>%
                        </p>
                    </div>
                </div>
            </template>
        </div>

        {{-- Clock + system state --}}
        <div class="glass glass--chip pointer-events-auto flex shrink-0 items-stretch gap-4 px-4 py-2.5 max-lg:gap-3 max-lg:px-3" x-sheen>
            <div class="leading-tight">
                <div class="flex items-baseline gap-1.5">
                    <span class="tnum text-[22px] font-bold text-white" x-text="$store.site.clock.time"></span>
                    <span class="text-[11px] font-semibold tracking-wide text-mist-300" x-text="$store.site.clock.zone"></span>
                </div>
                <p class="text-[11px] text-mist-300" x-text="$store.site.clock.date"></p>
            </div>
            <div class="w-px self-stretch bg-white/10"></div>
            <div class="flex items-center gap-2">
                <x-icon name="shield-check" class="size-5 text-state-normal"/>
                <span class="text-[13px] font-medium whitespace-nowrap text-mist-100 max-2xl:hidden">Sistem Normal</span>
                <span class="status-dot bg-state-normal"></span>
            </div>
        </div>

        {{-- Only the digital twin has pins to aim at, so the field lives with
             the stage rather than in the header of every page — and only while
             the base panorama is on screen, because inside a station there are
             no pins to steer to and the search would only be a way out of the
             picture the reader just opened. --}}
        @if ($stationSearch)
            {{-- `contents`, so the wrapper is not a flex item of its own: it
                 carries the open/closed rule, and the partial inside carries
                 the breakpoint. A plain block here stayed in the row after the
                 partial had hidden itself — nothing to see, but still two gaps
                 wide. --}}
            <div class="contents" x-show="!$store.viewer.open" x-cloak>
                @include('partials.station-search')
            </div>
        @endif
    </div>

    {{-- Actions --}}
    <div class="pointer-events-auto flex items-center gap-2.5">
        {{-- Notifications. The bell opens the standing alerts in place, and
             the way on to the full history is the link at the bottom of the
             list — a bell that navigates away on the first tap leaves no
             room to glance at what is wrong and carry on. Alerts come from
             the same store as the summary panel, so the two cannot disagree. --}}
        @php($activeAlerts = ($dashboard['alerts'] ?? null) ? count($dashboard['alerts']) : null)
        <div class="relative" x-data="{ open: false }"
             @click.outside="open = false" @keydown.escape.window="open = false">
            <button type="button" class="glass glass--chip glass-button relative size-11"
                    title="Peringatan" aria-label="Peringatan aktif"
                    aria-haspopup="true" :aria-expanded="open"
                    @click="open = !open">
                <x-icon name="bell" class="size-5"/>
                <span class="absolute -right-1 -top-1 grid size-5 place-items-center rounded-full bg-state-bahaya text-[10px] font-bold text-white shadow-lg"
                      x-text="$store.site.dashboard ? $store.site.dashboard.alerts.filter(a => !a.is_resolved).length : {{ $activeAlerts ?? 0 }}">
                </span>
            </button>

            <div x-show="open" x-cloak x-transition.origin.top.right
                 class="glass glass--panel glass--menu absolute right-0 z-10 mt-2 w-[360px] max-w-[calc(100vw-1.5rem)] p-1.5 max-sm:fixed max-sm:inset-x-3 max-sm:right-auto max-sm:top-[4.5rem] max-sm:w-auto">
                <div class="flex items-center justify-between px-2.5 py-2">
                    <p class="text-[13px] font-semibold text-white">Peringatan Aktif</p>
                    <span class="tnum text-[11px] text-mist-300"
                          x-text="($store.site.dashboard?.alerts ?? []).filter(a => !a.is_resolved).length + ' aktif'"></span>
                </div>

                <div class="max-h-[340px] overflow-y-auto">
                    <template x-for="alert in ($store.site.dashboard?.alerts ?? []).filter(a => !a.is_resolved)" :key="alert.id">
                        <a href="{{ route('alerts') }}" class="nav-item items-start gap-2.5 py-2">
                            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-xl"
                                  :style="`background:${window.statusColor(alert.level)}22;color:${window.statusColor(alert.level)}`">
                                <x-icon name="warning" class="size-4"/>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[12.5px] font-semibold text-white" x-text="alert.title"></span>
                                <span class="block truncate text-[11px] text-mist-300" x-text="alert.message"></span>
                                <span class="tnum mt-0.5 block text-[10.5px] text-mist-400"
                                      x-text="(alert.station?.name ? alert.station.name + ' · ' : '') + alert.triggered_label"></span>
                            </span>
                        </a>
                    </template>

                    <template x-if="($store.site.dashboard?.alerts ?? []).filter(a => !a.is_resolved).length === 0">
                        <p class="flex items-center gap-2 rounded-xl px-3 py-4 text-[12px] text-mist-300">
                            <x-icon name="check" class="size-4 text-state-normal"/>
                            Tidak ada peringatan aktif.
                        </p>
                    </template>
                </div>

                <div class="my-1.5 h-px bg-white/10"></div>

                <a href="{{ route('alerts') }}"
                   class="nav-item justify-center gap-2 text-[12.5px] font-semibold text-brand-300 hover:text-brand-200">
                    Lihat semua peringatan
                    <x-icon name="chevron-right" class="size-3.5"/>
                </a>
            </div>
        </div>

        @if ($stationSearch)
            {{-- Same again, and this is the one that showed: an empty block
                 between the bell and the help button put a gap either side of
                 nothing, so those two chips sat twice as far apart on the twin
                 as on every other page. --}}
            <div class="contents" x-show="!$store.viewer.open" x-cloak>
                @include('partials.station-search', ['compact' => true])
            </div>
        @endif

        <button type="button" class="glass glass--chip glass-button size-11" title="Bantuan" aria-label="Bantuan"
                x-data @click="$dispatch('open-help')">
            <x-icon name="help" class="size-5"/>
        </button>

        <div class="relative max-sm:hidden" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" class="glass glass--chip glass-button gap-2.5 px-3.5 py-2.5 max-xl:px-2.5" @click="open = !open">
                <span class="grid size-7 place-items-center rounded-full bg-white/10">
                    <x-icon name="user" class="size-4"/>
                </span>
                <span class="text-[13px] font-semibold text-white max-xl:hidden">{{ auth()->user()?->name ?? 'Pengguna' }}</span>
                <x-icon name="chevron-down" class="size-4 text-mist-300 max-xl:hidden"/>
            </button>

            <div x-show="open" x-cloak x-transition.origin.top.right
                 class="glass glass--panel glass--menu absolute right-0 z-10 mt-2 w-64 p-1.5">
                <div class="flex items-center gap-2.5 rounded-xl px-2.5 py-2">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-500/25 text-brand-200">
                        <x-icon name="user" class="size-4"/>
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-[13px] font-semibold text-white">{{ auth()->user()?->name }}</span>
                        <span class="block truncate text-[11px] text-mist-300">{{ auth()->user()?->unit }}</span>
                        <span class="mt-0.5 inline-block rounded-md bg-brand-500/18 px-1.5 py-0.5 text-[10px] font-semibold text-brand-200">
                            {{ auth()->user()?->roleLabel() }}
                        </span>
                    </span>
                </div>

                <div class="my-1.5 h-px bg-white/10"></div>

                @can('users.manage')
                    <a href="{{ route('users') }}" class="nav-item gap-2.5 text-[13px]">
                        <x-icon name="users" class="size-4 shrink-0"/>
                        Pengguna &amp; Akses
                    </a>
                @endcan

                <a href="{{ route('settings') }}" class="nav-item gap-2.5 text-[13px]">
                    <x-icon name="cog" class="size-4 shrink-0"/>
                    Pengaturan
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="nav-item w-full gap-2.5 text-left text-[13px] text-state-bahaya hover:bg-state-bahaya/12">
                        <x-icon name="logout" class="size-4 shrink-0"/>
                        Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

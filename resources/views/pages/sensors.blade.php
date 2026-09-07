@extends('layouts.app')

@section('title', 'Data Instrumentasi')

@section('stage')
    @php($activeLabel = $activeType ? ($types[$activeType] ?? $activeType) : null)

    <x-page-shell wide title="Data Instrumentasi"
                  subtitle="{{ $activeType
                      ? count($stations).' dari '.$totalStations.' stasiun — '.$activeLabel
                      : $totalStations.' stasiun instrumentasi terpasang di Bendungan Budong Budong.' }}">
        <x-slot:actions>
            {{-- Thirteen types laid out as pills wrapped onto a second line and
                 pushed the table down; one menu holds them all and says which
                 one is on. --}}
            <div class="flex items-center gap-2">
                @if ($activeType)
                    <a href="{{ route('sensors') }}"
                       class="glass glass--chip glass-button gap-1.5 px-3 py-2.5 text-[12px] font-semibold"
                       title="Hapus filter" aria-label="Hapus filter tipe">
                        <x-icon name="x" class="size-3.5"/>
                        Semua
                    </a>
                @endif

                <div class="relative" x-data="{ open: false }"
                     @click.outside="open = false"
                     @keydown.escape.stop="open = false">
                    <button type="button"
                            class="glass glass--chip glass-button gap-2 px-3.5 py-2.5 text-[12px] font-semibold"
                            aria-haspopup="menu" :aria-expanded="open"
                            @click="open = ! open">
                        <x-icon name="filter" class="size-4 shrink-0 text-mist-300"/>
                        <span class="max-w-[190px] truncate">{{ $activeLabel ?? 'Semua tipe' }}</span>
                        <span class="tnum rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] font-bold">
                            {{ count($stations) }}
                        </span>
                        <x-icon name="chevron-down" class="size-4 shrink-0 text-mist-300 transition"
                                ::class="open && 'rotate-180'"/>
                    </button>

                    <div x-show="open" x-cloak x-transition.origin.top.right
                         {{-- The trigger sits on the left of the header on a phone
                              and on the right from sm up; the menu hangs off the
                              same edge or it runs off the screen. --}}
                         class="glass glass--panel glass--menu absolute left-0 z-20 mt-2 w-[268px] max-w-[calc(100vw-2rem)] p-1.5
                                sm:left-auto sm:right-0"
                         role="menu" aria-label="Filter tipe stasiun">

                        <a href="{{ route('sensors') }}" role="menuitem"
                           class="nav-item gap-2.5 text-[12.5px] {{ $activeType ? '' : 'nav-item--active' }}"
                           @if (! $activeType) aria-current="true" @endif>
                            <span class="flex-1 truncate">Semua tipe</span>
                            <span class="tnum text-[11px] text-mist-400">{{ $totalStations }}</span>
                        </a>

                        <div class="my-1.5 h-px bg-white/10"></div>

                        <div class="scroll-y max-h-[320px] pr-0.5">
                            @foreach ($types as $type => $label)
                                <a href="{{ route('sensors', ['tipe' => $type]) }}" role="menuitem"
                                   class="nav-item gap-2.5 text-[12.5px] {{ $activeType === $type ? 'nav-item--active' : '' }}"
                                   @if ($activeType === $type) aria-current="true" @endif>
                                    <span class="flex-1 truncate">{{ $label }}</span>
                                    <span class="tnum text-[11px] text-mist-400">{{ $typeCounts[$type] ?? 0 }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </x-slot:actions>

        {{-- A sixteen-column table on a 375px screen turns every station name
             into five lines, so phones get the same rows as cards instead. --}}
        <div class="glass glass--panel overflow-hidden p-0 max-sm:hidden" x-sheen>
            <div class="table-scroll">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-white/10 bg-white/4 text-[10.5px] tracking-wide text-mist-300 uppercase">
                        <th class="px-4 py-3 font-semibold">Stasiun</th>
                        <th class="px-4 py-3 font-semibold">Tipe</th>
                        <th class="px-4 py-3 font-semibold">Zona</th>
                        <th class="px-4 py-3 font-semibold">Parameter Utama</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @forelse ($stations as $station)
                        <tr class="transition hover:bg-white/5">
                            <td class="px-4 py-3">
                                <p class="text-[13px] font-semibold text-white">{{ $station['name'] }}</p>
                                <p class="text-[11px] text-mist-400">{{ strtoupper($station['code']) }}</p>
                            </td>
                            <td class="px-4 py-3 text-[12.5px] text-mist-200">{{ $station['type_label'] }}</td>
                            <td class="px-4 py-3 text-[12.5px] text-mist-200">{{ $station['zone'] ?? '—' }}</td>
                            <td class="tnum px-4 py-3 text-[12.5px] font-semibold text-white">{{ $station['caption'] }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                      style="background: {{ config("dam.statuses.{$station['status']}.color") }}1f;
                                             color: {{ config("dam.statuses.{$station['status']}.color") }}">
                                    <span class="status-dot" style="background: {{ config("dam.statuses.{$station['status']}.color") }}"></span>
                                    {{ $station['status_label'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($station['has_panorama'])
                                        <a href="{{ route('twin.station', $station['code']) }}"
                                           class="glass glass--chip glass-button px-3 py-1.5 text-[11.5px] font-semibold">
                                            <x-icon name="eye" class="size-3.5"/> 360°
                                        </a>
                                    @endif
                                    <a href="{{ route('analytics') }}?stasiun={{ $station['code'] }}"
                                       class="glass glass--chip glass-button px-3 py-1.5 text-[11.5px] font-semibold">
                                        <x-icon name="chart-line" class="size-3.5"/> Grafik
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center">
                                <p class="text-[13px] font-semibold text-white">Tidak ada stasiun pada filter ini</p>
                                <p class="mt-1 text-[11.5px] text-mist-300">Pilih tipe lain atau kembali ke “Semua”.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
        <ul class="space-y-2.5 sm:hidden">
            @forelse ($stations as $station)
                <li class="glass glass--panel p-3.5" x-sheen>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-[13.5px] font-semibold text-white">{{ $station['name'] }}</p>
                            <p class="text-[11px] text-mist-400">
                                {{ strtoupper($station['code']) }} · {{ $station['type_label'] }}
                            </p>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold"
                              style="background: {{ config("dam.statuses.{$station['status']}.color") }}1f;
                                     color: {{ config("dam.statuses.{$station['status']}.color") }}">
                            <span class="status-dot" style="background: {{ config("dam.statuses.{$station['status']}.color") }}"></span>
                            {{ $station['status_label'] }}
                        </span>
                    </div>

                    <div class="mt-2 flex items-end justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[10.5px] tracking-wide text-mist-400 uppercase">Parameter utama</p>
                            <p class="tnum truncate text-[15px] font-semibold text-white">{{ $station['caption'] }}</p>
                            <p class="truncate text-[11px] text-mist-300">{{ $station['zone'] ?? '—' }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($station['has_panorama'])
                                <a href="{{ route('twin.station', $station['code']) }}"
                                   class="glass glass--chip glass-button px-3.5 py-2.5 text-[11.5px] font-semibold"
                                   aria-label="Buka panorama 360° {{ $station['name'] }}">
                                    <x-icon name="eye" class="size-3.5"/> 360°
                                </a>
                            @endif
                            <a href="{{ route('analytics') }}?stasiun={{ $station['code'] }}"
                               class="glass glass--chip glass-button px-3.5 py-2.5 text-[11.5px] font-semibold"
                               aria-label="Lihat grafik {{ $station['name'] }}">
                                <x-icon name="chart-line" class="size-3.5"/> Grafik
                            </a>
                        </div>
                    </div>
                </li>
            @empty
                <li class="glass glass--panel p-6 text-center" x-sheen>
                    <p class="text-[13px] font-semibold text-white">Tidak ada stasiun pada filter ini</p>
                    <p class="mt-1 text-[11.5px] text-mist-300">Pilih tipe lain atau kembali ke “Semua”.</p>
                </li>
            @endforelse
        </ul>
    </x-page-shell>
@endsection

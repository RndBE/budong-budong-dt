@extends('layouts.app')

@section('title', 'Peringatan')

@section('stage')
    <x-page-shell wide title="Peringatan & Kejadian"
                  subtitle="Riwayat pelampauan ambang batas seluruh instrumentasi.">
        <x-slot:actions>
            <div class="glass glass--chip flex items-center gap-1 p-1.5">
                <a href="{{ route('alerts') }}"
                   class="rounded-xl px-3 py-1.5 max-sm:px-4 max-sm:py-3 text-[12px] font-semibold transition {{ $level ? 'text-mist-300 hover:bg-white/8' : 'bg-brand-500/90 text-white' }}">
                    Semua
                </a>
                @foreach (['waspada', 'siaga', 'bahaya'] as $option)
                    <a href="{{ route('alerts', ['level' => $option]) }}"
                       class="rounded-xl px-3 py-1.5 max-sm:px-4 max-sm:py-3 text-[12px] font-semibold capitalize transition {{ $level === $option ? 'bg-brand-500/90 text-white' : 'text-mist-300 hover:bg-white/8' }}">
                        {{ $option }}
                    </a>
                @endforeach
            </div>
        </x-slot:actions>

        <div x-data="{
                 async act(id, action) {
                     await fetch(`/api/alerts/${id}/${action}`, {
                         method: 'POST',
                         headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                     });
                     window.location.reload();
                 }
             }">
        <div class="glass glass--panel overflow-hidden max-sm:hidden" x-sheen>
            <div class="table-scroll">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-white/10 bg-white/4 text-[10.5px] tracking-wide text-mist-300 uppercase">
                        <th class="px-4 py-3 font-semibold">Waktu</th>
                        <th class="px-4 py-3 font-semibold">Level</th>
                        <th class="px-4 py-3 font-semibold">Kejadian</th>
                        <th class="px-4 py-3 font-semibold">Stasiun</th>
                        <th class="px-4 py-3 font-semibold">Nilai / Ambang</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @forelse ($alerts as $alert)
                        @php($color = config("dam.statuses.{$alert->level}.color", '#fbbf24'))
                        <tr class="align-top transition hover:bg-white/5">
                            <td class="tnum px-4 py-3 text-[12px] text-mist-200 whitespace-nowrap">
                                {{ $alert->triggered_at->translatedFormat('d M Y') }}<br>
                                <span class="text-mist-400">{{ $alert->triggered_at->format('H:i') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold capitalize"
                                      style="background: {{ $color }}1f; color: {{ $color }}">
                                    <span class="status-dot" style="background: {{ $color }}"></span>
                                    {{ $alert->level }}
                                </span>
                            </td>
                            <td class="max-w-[420px] px-4 py-3">
                                <p class="text-[12.5px] font-semibold text-white">{{ $alert->title }}</p>
                                <p class="text-[11.5px] text-mist-300">{{ $alert->message }}</p>
                            </td>
                            <td class="px-4 py-3 text-[12px] text-mist-200">
                                @if ($alert->station)
                                    <a href="{{ route('twin.station', $alert->station->code) }}" class="hover:text-white">
                                        {{ $alert->station->name }}
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="tnum px-4 py-3 text-[12px] text-mist-200 whitespace-nowrap">
                                {{ $alert->value !== null ? number_format((float) $alert->value, 2, ',', '.') : '—' }}
                                <span class="text-mist-400">/</span>
                                {{ $alert->threshold !== null ? number_format((float) $alert->threshold, 2, ',', '.') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-[12px]">
                                @if ($alert->resolved_at)
                                    <span class="text-state-normal">Selesai</span>
                                @elseif ($alert->acknowledged_at)
                                    <span class="text-brand-300">Ditinjau</span>
                                @else
                                    <span class="text-state-waspada">Aktif</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if (! $alert->resolved_at && auth()->user()->can('alerts.handle'))
                                    <div class="flex items-center justify-end gap-1.5">
                                        @unless ($alert->acknowledged_at)
                                            <button type="button" class="glass glass--chip glass-button px-3 py-1.5 text-[11px] font-semibold"
                                                    @click="act({{ $alert->id }}, 'acknowledge')">
                                                Tinjau
                                            </button>
                                        @endunless
                                        <button type="button" class="glass glass--chip glass-button px-3 py-1.5 text-[11px] font-semibold"
                                                @click="act({{ $alert->id }}, 'resolve')">
                                            Selesaikan
                                        </button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center">
                                <p class="text-[13px] font-semibold text-white">Tidak ada peringatan</p>
                                <p class="mt-1 text-[11.5px] text-mist-300">
                                    Seluruh parameter berada di bawah ambang batas untuk filter ini.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

        {{-- Below sm the same alerts are cards: a seven-column table on a phone
             is a horizontal scroll bar with the actions hidden inside it. --}}
        <ul class="space-y-2.5 sm:hidden">
            @forelse ($alerts as $alert)
                @php($color = config("dam.statuses.{$alert->level}.color", '#fbbf24'))
                <li class="glass glass--panel p-3.5" x-sheen>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-[13px] font-semibold text-white">{{ $alert->title }}</p>
                            <p class="tnum text-[11px] text-mist-400">
                                {{ $alert->triggered_at->translatedFormat('d M Y') }} · {{ $alert->triggered_at->format('H:i') }}
                            </p>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold capitalize"
                              style="background: {{ $color }}1f; color: {{ $color }}">
                            <span class="status-dot" style="background: {{ $color }}"></span>
                            {{ $alert->level }}
                        </span>
                    </div>

                    <p class="mt-2 text-[11.5px] leading-relaxed text-mist-300">{{ $alert->message }}</p>

                    <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-mist-400">
                        @if ($alert->station)
                            <a href="{{ route('twin.station', $alert->station->code) }}" class="text-mist-200 hover:text-white">
                                {{ $alert->station->name }}
                            </a>
                            <span>·</span>
                        @endif
                        <span class="tnum">
                            {{ $alert->value !== null ? number_format((float) $alert->value, 2, ',', '.') : '—' }}
                            / {{ $alert->threshold !== null ? number_format((float) $alert->threshold, 2, ',', '.') : '—' }}
                        </span>
                        <span>·</span>
                        @if ($alert->resolved_at)
                            <span class="text-state-normal">Selesai</span>
                        @elseif ($alert->acknowledged_at)
                            <span class="text-brand-300">Ditinjau</span>
                        @else
                            <span class="text-state-waspada">Aktif</span>
                        @endif
                    </p>

                    @if (! $alert->resolved_at && auth()->user()->can('alerts.handle'))
                        <div class="mt-2.5 flex items-center gap-2">
                            @unless ($alert->acknowledged_at)
                                <button type="button"
                                        class="min-h-10 flex-1 rounded-xl bg-white/8 px-3 text-[12px] font-medium text-mist-100 transition hover:bg-white/16"
                                        @click="act({{ $alert->id }}, 'acknowledge')">
                                    Tinjau
                                </button>
                            @endunless
                            <button type="button"
                                    class="min-h-10 flex-1 rounded-xl bg-brand-500/22 px-3 text-[12px] font-semibold text-brand-100 transition hover:bg-brand-500/32"
                                    @click="act({{ $alert->id }}, 'resolve')">
                                Selesaikan
                            </button>
                        </div>
                    @endif
                </li>
            @empty
                <li class="glass glass--panel px-4 py-10 text-center" x-sheen>
                    <p class="text-[13px] font-semibold text-white">Tidak ada peringatan</p>
                    <p class="mt-1 text-[11.5px] text-mist-300">
                        Seluruh parameter berada di bawah ambang batas untuk filter ini.
                    </p>
                </li>
            @endforelse
        </ul>
        </div>

        <div class="mt-3.5 pb-2 [&_a]:text-mist-200 [&_span]:text-mist-400">
            {{ $alerts->links() }}
        </div>
    </x-page-shell>
@endsection

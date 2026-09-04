@extends('layouts.app')

@section('title', 'Data Sensor')

@section('stage')
    <x-page-shell wide title="Data Sensor"
                  subtitle="{{ count($stations) }} stasiun instrumentasi terpasang di Bendungan Budong Budong.">
        <x-slot:actions>
            <div class="glass glass--chip flex max-w-full flex-wrap items-center gap-1 overflow-x-auto p-1.5">
                <a href="{{ route('sensors') }}"
                   class="rounded-xl px-3 py-1.5 text-[12px] font-semibold transition {{ $activeType ? 'text-mist-300 hover:bg-white/8' : 'bg-brand-500/90 text-white' }}">
                    Semua
                </a>
                @foreach ($types as $type => $label)
                    <a href="{{ route('sensors', ['tipe' => $type]) }}"
                       class="rounded-xl px-3 py-1.5 text-[12px] font-semibold transition {{ $activeType === $type ? 'bg-brand-500/90 text-white' : 'text-mist-300 hover:bg-white/8' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </x-slot:actions>

        <div class="glass glass--panel overflow-hidden p-0" x-sheen>
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
                    @foreach ($stations as $station)
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
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </x-page-shell>
@endsection

@section('panel')
    <div></div>
@endsection

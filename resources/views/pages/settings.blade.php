@extends('layouts.app')

@section('title', 'Pengaturan')

@section('stage')
    <x-page-shell wide title="Pengaturan"
                  subtitle="Tampilan peta, ambang batas instrumentasi, dan integrasi telemetri.">

        <div class="grid grid-cols-3 gap-3.5 max-xl:grid-cols-2 max-sm:grid-cols-1">

            {{-- Map appearance --}}
            <div class="glass glass--panel p-4" x-sheen>
                <h2 class="text-[14px] font-semibold text-white">Tampilan Peta</h2>
                <p class="mt-1 text-[11.5px] leading-relaxed text-mist-300">
                    Secara bawaan latar bendungan mengikuti posisi matahari sebenarnya di lokasi
                    ({{ $dam->latitude }}, {{ $dam->longitude }}, {{ $dam->timezone }}).
                    Pilih fase tertentu untuk mengunci tampilan.
                </p>

                <div class="mt-3 grid grid-cols-5 gap-1.5">
                    @foreach (['auto' => 'Otomatis', 'dawn' => 'Fajar', 'day' => 'Siang', 'dusk' => 'Senja', 'night' => 'Malam'] as $key => $label)
                        <button type="button"
                                class="rounded-xl px-2 py-2 text-[11px] font-semibold transition"
                                :class="$store.site.skin === '{{ $key }}' ? 'bg-brand-500/90 text-white' : 'bg-white/8 text-mist-200 hover:bg-white/14'"
                                @click="$store.site.setSkin('{{ $key }}')">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="mt-3 space-y-2 text-[11.5px]">
                    <div class="glass glass--inset flex items-center justify-between px-3 py-2">
                        <span class="text-mist-300">Fase sekarang</span>
                        <span class="font-semibold text-white" x-text="$store.site.environment?.sun?.phase_label ?? '—'"></span>
                    </div>
                    <div class="glass glass--inset flex items-center justify-between px-3 py-2">
                        <span class="text-mist-300">Elevasi matahari</span>
                        <span class="tnum font-semibold text-white"
                              x-text="($store.site.environment?.sun?.elevation ?? 0).toFixed(1) + '°'"></span>
                    </div>
                    <div class="glass glass--inset flex items-center justify-between px-3 py-2">
                        <span class="text-mist-300">Terbit / terbenam</span>
                        <span class="tnum font-semibold text-white">
                            <span x-text="$store.site.environment?.sun?.sunrise ?? '—'"></span> /
                            <span x-text="$store.site.environment?.sun?.sunset ?? '—'"></span>
                        </span>
                    </div>
                </div>
            </div>

            {{-- Dam identity --}}
            <div class="glass glass--panel p-4" x-sheen>
                <h2 class="mb-3 text-[14px] font-semibold text-white">Identitas Bendungan</h2>
                <dl class="space-y-2 text-[12px]">
                    @foreach ([
                        'Nama' => $dam->name,
                        'Pengelola' => $dam->authority,
                        'Sungai' => $dam->river,
                        'Kabupaten' => $dam->regency.', '.$dam->province,
                        'Koordinat' => $dam->latitude.', '.$dam->longitude,
                        'Zona Waktu' => $dam->timezone,
                    ] as $label => $value)
                        <div class="flex items-start justify-between gap-3 border-b border-white/6 pb-2 last:border-0">
                            <dt class="text-mist-300">{{ $label }}</dt>
                            <dd class="text-right font-medium text-mist-100">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- Telemetry integration --}}
            <div class="glass glass--panel p-4" x-sheen>
                <h2 class="mb-2 text-[14px] font-semibold text-white">Integrasi Telemetri</h2>
                <p class="text-[11.5px] leading-relaxed text-mist-300">
                    Driver aktif:
                    <span class="font-mono font-semibold text-brand-300">{{ config('telemetry.driver') }}</span>.
                </p>

                <div class="mt-3 space-y-2 text-[11px]">
                    <div class="glass glass--inset px-3 py-2.5">
                        <p class="font-semibold text-mist-100">Tarik dari API logger</p>
                        <p class="mt-1 font-mono text-[10.5px] leading-relaxed text-mist-300">
                            TELEMETRY_DRIVER=http<br>
                            TELEMETRY_BASE_URL=https://…<br>
                            TELEMETRY_TOKEN=…
                        </p>
                    </div>
                    <div class="glass glass--inset px-3 py-2.5">
                        <p class="font-semibold text-mist-100">Kirim dari perangkat</p>
                        <p class="mt-1 font-mono text-[10.5px] leading-relaxed text-mist-300">
                            POST /api/ingest<br>
                            X-Ingest-Token: …<br>
                            {"station":"awlr-hulu","metrics":{"water_level":93.881}}
                        </p>
                    </div>
                    <div class="glass glass--inset px-3 py-2.5">
                        <p class="font-semibold text-mist-100">Data demo</p>
                        <p class="mt-1 font-mono text-[10.5px] text-mist-300">php artisan telemetry:simulate</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Thresholds --}}
        @php
            $metricRows = $stations->flatMap(fn ($station) => $station->metrics->map(fn ($metric) => [
                'id' => $metric->id,
                'station' => $station->short_name ?? $station->name,
                'label' => $metric->label,
                'unit' => $metric->unit,
                'warning_threshold' => $metric->warning_threshold,
                'alert_threshold' => $metric->alert_threshold,
                'critical_threshold' => $metric->critical_threshold,
            ]))->values();
        @endphp

        <div class="glass glass--panel mt-3.5 overflow-hidden pb-2" x-sheen x-data="thresholdForm(@js($metricRows))">
            <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                <div>
                    <h2 class="text-[14px] font-semibold text-white">Ambang Batas Instrumentasi</h2>
                    <p class="text-[11.5px] text-mist-300">Perubahan langsung dipakai untuk klasifikasi status dan pembangkitan peringatan.</p>
                </div>
                <div class="flex items-center gap-2.5">
                    <span class="text-[11.5px] text-state-waspada" x-show="dirty.size > 0 && !saved" x-cloak
                          x-text="`${dirty.size} nilai belum disimpan`"></span>
                    <span class="text-[11.5px] text-state-normal" x-show="saved" x-cloak>Tersimpan</span>
                    <span class="text-[11.5px] text-state-bahaya" x-show="failed" x-cloak>Gagal menyimpan — coba lagi</span>
                    @can('thresholds.edit')
                        <button type="button" class="glass glass--chip glass-button px-4 py-2 text-[12px] font-semibold"
                                :disabled="saving" @click="save()">
                            <x-icon name="save" class="size-4"/>
                            <span x-text="saving ? 'Menyimpan…' : 'Simpan'"></span>
                        </button>
                    @else
                        <span class="text-[11.5px] text-mist-400">Peran Anda hanya dapat melihat ambang batas.</span>
                    @endcan
                </div>
            </div>

            <div class="scroll-y max-h-[46vh] max-sm:hidden">
                <table class="w-full min-w-[640px] border-collapse text-left">
                    <thead class="sticky top-0 bg-ink-900/80 backdrop-blur">
                        <tr class="text-[10.5px] tracking-wide text-mist-300 uppercase">
                            <th class="px-4 py-2.5 font-semibold">Stasiun</th>
                            <th class="px-4 py-2.5 font-semibold">Parameter</th>
                            <th class="px-4 py-2.5 font-semibold">Waspada</th>
                            <th class="px-4 py-2.5 font-semibold">Siaga</th>
                            <th class="px-4 py-2.5 font-semibold">Bahaya</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        <template x-for="row in rows" :key="row.id">
                            <tr class="transition hover:bg-white/4">
                                <td class="px-4 py-2 text-[12px] text-mist-200" x-text="row.station"></td>
                                <td class="px-4 py-2 text-[12px] text-white">
                                    <span x-text="row.label"></span>
                                    <span class="text-mist-400" x-text="row.unit ? ` (${row.unit})` : ''"></span>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="number" step="any" x-model.number="row.warning_threshold"
                                           @disabled(! auth()->user()->can('thresholds.edit'))
                                           :aria-label="`Ambang Waspada — ${row.station}, ${row.label}`"
                                           :class="dirty.has(row.id) && 'ring-1 ring-brand-400/60'"
                                           @input="dirty.add(row.id)"
                                           class="tnum glass glass--inset w-24 px-2 py-1 text-[12px] text-state-waspada">
                                </td>
                                <td class="px-4 py-2">
                                    <input type="number" step="any" x-model.number="row.alert_threshold"
                                           @disabled(! auth()->user()->can('thresholds.edit'))
                                           :aria-label="`Ambang Siaga — ${row.station}, ${row.label}`"
                                           :class="dirty.has(row.id) && 'ring-1 ring-brand-400/60'"
                                           @input="dirty.add(row.id)"
                                           class="tnum glass glass--inset w-24 px-2 py-1 text-[12px] text-state-siaga">
                                </td>
                                <td class="px-4 py-2">
                                    <input type="number" step="any" x-model.number="row.critical_threshold"
                                           @disabled(! auth()->user()->can('thresholds.edit'))
                                           :aria-label="`Ambang Bahaya — ${row.station}, ${row.label}`"
                                           :class="dirty.has(row.id) && 'ring-1 ring-brand-400/60'"
                                           @input="dirty.add(row.id)"
                                           class="tnum glass glass--inset w-24 px-2 py-1 text-[12px] text-state-bahaya">
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- On a phone a row of three number columns is unusable sideways,
                 so each parameter becomes a card with its three limits. --}}
            <div class="scroll-y max-h-[60vh] space-y-2.5 px-3 py-3 sm:hidden">
                <template x-for="row in rows" :key="'m' + row.id">
                    <div class="glass glass--inset p-3">
                        <p class="text-[12.5px] font-semibold text-white">
                            <span x-text="row.label"></span>
                            <span class="text-mist-400" x-text="row.unit ? ` (${row.unit})` : ''"></span>
                        </p>
                        <p class="text-[11px] text-mist-400" x-text="row.station"></p>

                        <div class="mt-2.5 grid grid-cols-3 gap-2">
                            <label class="block">
                                <span class="mb-1 block text-[10.5px] font-medium text-state-waspada">Waspada</span>
                                <input type="number" step="any" x-model.number="row.warning_threshold"
                                       @disabled(! auth()->user()->can('thresholds.edit'))
                                       :aria-label="`Ambang Waspada — ${row.station}, ${row.label}`"
                                       :class="dirty.has(row.id) && 'ring-1 ring-brand-400/60'"
                                       @input="dirty.add(row.id)"
                                       class="tnum glass glass--inset min-h-10 w-full px-2 text-[12px] text-state-waspada">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-[10.5px] font-medium text-state-siaga">Siaga</span>
                                <input type="number" step="any" x-model.number="row.alert_threshold"
                                       @disabled(! auth()->user()->can('thresholds.edit'))
                                       :aria-label="`Ambang Siaga — ${row.station}, ${row.label}`"
                                       :class="dirty.has(row.id) && 'ring-1 ring-brand-400/60'"
                                       @input="dirty.add(row.id)"
                                       class="tnum glass glass--inset min-h-10 w-full px-2 text-[12px] text-state-siaga">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-[10.5px] font-medium text-state-bahaya">Bahaya</span>
                                <input type="number" step="any" x-model.number="row.critical_threshold"
                                       @disabled(! auth()->user()->can('thresholds.edit'))
                                       :aria-label="`Ambang Bahaya — ${row.station}, ${row.label}`"
                                       :class="dirty.has(row.id) && 'ring-1 ring-brand-400/60'"
                                       @input="dirty.add(row.id)"
                                       class="tnum glass glass--inset min-h-10 w-full px-2 text-[12px] text-state-bahaya">
                            </label>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </x-page-shell>
@endsection

@extends('layouts.app')

@section('title', 'Laporan')

@section('stage')
    <x-page-shell title="Laporan Monitoring"
                  subtitle="Bangkitkan laporan periodik dalam format PDF atau CSV data mentah.">

        <div class="glass glass--panel p-4" x-sheen x-data="reportForm()">
            <h2 class="mb-3 text-[14px] font-semibold text-white">Buat Laporan</h2>

            <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                <label>
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Periode</span>
                    <select class="glass glass--inset w-full appearance-none px-3.5 py-2.5 text-[12.5px] text-white focus:outline-none"
                            x-model="period">
                        <option class="bg-ink-800" value="harian">Harian</option>
                        <option class="bg-ink-800" value="mingguan">Mingguan</option>
                        <option class="bg-ink-800" value="bulanan">Bulanan</option>
                        <option class="bg-ink-800" value="kustom">Kustom</option>
                    </select>
                </label>

                <label>
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Format</span>
                    <select class="glass glass--inset w-full appearance-none px-3.5 py-2.5 text-[12.5px] text-white focus:outline-none"
                            x-model="format">
                        <option class="bg-ink-800" value="pdf">PDF ringkasan</option>
                        <option class="bg-ink-800" value="csv">CSV data mentah</option>
                    </select>
                </label>

                <label>
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Mulai</span>
                    <input type="date" x-model="start"
                           class="glass glass--inset w-full px-3.5 py-2.5 text-[12.5px] text-white focus:outline-none">
                </label>

                <label>
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Sampai</span>
                    <input type="date" x-model="end"
                           class="glass glass--inset w-full px-3.5 py-2.5 text-[12.5px] text-white focus:outline-none">
                </label>
            </div>

            <div class="mt-3.5">
                <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Bagian yang disertakan</span>
                <div class="flex flex-wrap gap-2">
                    @foreach ([
                        'ringkasan' => 'Ringkasan bendungan',
                        'parameter' => 'Parameter utama',
                        'instrumentasi' => 'Tabel instrumentasi',
                        'peringatan' => 'Peringatan periode',
                    ] as $key => $label)
                        <label class="glass glass--inset flex cursor-pointer items-center gap-2 px-3 py-2 text-[12px] text-mist-100">
                            <input type="checkbox" value="{{ $key }}" x-model="sections" class="accent-brand-500">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="button"
                        class="glass glass--chip glass-button px-5 py-2.5 text-[12.5px] font-semibold"
                        :disabled="busy" @click="submit()">
                    <x-icon name="download" class="size-4"/>
                    <span x-text="busy ? 'Menyusun laporan…' : 'Buat & Unduh'"></span>
                </button>
                <p class="text-[11.5px] text-state-bahaya" x-show="error" x-text="error"></p>
            </div>
        </div>

        <div class="glass glass--panel mt-3.5 overflow-hidden pb-2" x-sheen>
            <div class="border-b border-white/10 px-4 py-3">
                <h2 class="text-[14px] font-semibold text-white">Riwayat Laporan</h2>
            </div>

            <div class="table-scroll">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="bg-white/4 text-[10.5px] tracking-wide text-mist-300 uppercase">
                        <th class="px-4 py-2.5 font-semibold">Judul</th>
                        <th class="px-4 py-2.5 font-semibold">Periode</th>
                        <th class="px-4 py-2.5 font-semibold">Dibuat</th>
                        <th class="px-4 py-2.5 font-semibold">Format</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @forelse ($reports as $report)
                        <tr class="transition hover:bg-white/5">
                            <td class="px-4 py-3 text-[12.5px] font-medium text-white">{{ $report->title }}</td>
                            <td class="tnum px-4 py-3 text-[12px] text-mist-200">
                                {{ $report->period_start->translatedFormat('d M Y') }} –
                                {{ $report->period_end->translatedFormat('d M Y') }}
                            </td>
                            <td class="tnum px-4 py-3 text-[12px] text-mist-300">
                                {{ $report->created_at->translatedFormat('d M Y H:i') }}
                            </td>
                            <td class="px-4 py-3 text-[12px] text-mist-200 uppercase">{{ $report->format }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('api.reports.download', $report) }}"
                                   class="glass glass--chip glass-button px-3 py-1.5 text-[11.5px] font-semibold">
                                    <x-icon name="download" class="size-3.5"/> Unduh
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-[12px] text-mist-400">
                                Belum ada laporan yang dibuat.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </x-page-shell>
@endsection

@section('panel')
    @include('partials.right.summary')
@endsection

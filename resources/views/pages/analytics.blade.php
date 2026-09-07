@extends('layouts.app')

@section('title', 'Analisa & Grafik')

@section('stage')
    {{-- Two readings of the same series. Grafik lays every parameter out on one
         shared range; Analisa puts the ones the reader ticks into a single
         chart. A card in the grid is a shortcut into Analisa. --}}
    <x-page-shell wide title="Analisa & Grafik"
                  subtitle="Bandingkan tren tiap parameter terhadap ambang batasnya.">

        <div x-data="analyticsBoard(@js($stations))">

            {{-- Controls: one station, one range, for everything on screen --}}
            <div class="glass glass--panel mb-3.5 flex flex-wrap items-end gap-3 p-3.5" x-sheen>
                <label class="min-w-[260px] flex-1">
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Stasiun</span>
                    <select class="glass glass--inset w-full appearance-none px-3.5 py-2.5 text-[12.5px] text-white"
                            :value="scope" @change="setScope($event.target.value)">
                        <option class="bg-ink-800" value="semua">Semua stasiun — parameter utama</option>
                        <template x-for="station in stations" :key="station.code">
                            <option class="bg-ink-800" :value="station.code" x-text="station.name"
                                    :selected="station.code === scope"></option>
                        </template>
                    </select>
                </label>

                <div>
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Rentang</span>
                    <div class="glass glass--inset flex items-center gap-0.5 p-0.5">
                        <template x-for="option in ['24h', '7d', '30d']" :key="'range' + option">
                            <button type="button" class="min-h-9 rounded-xl px-3.5 text-[11.5px] font-semibold transition"
                                    :class="range === option ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                    @click="setRange(option)" x-text="option"></button>
                        </template>
                    </div>
                </div>

                <div>
                    <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Tampilan</span>
                    <div class="glass glass--inset flex items-center gap-0.5 p-0.5" role="tablist"
                         aria-label="Tampilan analisa">
                        <button type="button" role="tab" class="flex min-h-9 items-center gap-2 rounded-xl px-3.5 text-[11.5px] font-semibold transition"
                                :aria-selected="mode === 'grafik'"
                                :class="mode === 'grafik' ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                @click="setMode('grafik')">
                            <x-icon name="layers" class="size-4"/>
                            Grafik
                        </button>
                        <button type="button" role="tab" class="flex min-h-9 items-center gap-2 rounded-xl px-3.5 text-[11.5px] font-semibold transition"
                                :aria-selected="mode === 'analisa'"
                                :class="mode === 'analisa' ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                @click="setMode('analisa')">
                            <x-icon name="chart-line" class="size-4"/>
                            Analisa
                        </button>
                    </div>
                </div>

                <span class="pb-2.5 text-[11px] text-mist-400" x-show="loading" x-cloak>memuat…</span>
            </div>

            {{-- Grafik: every parameter, two per row, flowing down --------- --}}
            <div x-show="mode === 'grafik'" class="grid gap-3.5 xl:grid-cols-2">
                <template x-for="card in cards" :key="card.id">
                    <button type="button"
                            class="glass glass--panel p-3.5 text-left transition hover:ring-1 hover:ring-brand-400/40"
                            title="Buka di Analisa"
                            @click="open(card)">
                        <div class="mb-2 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-semibold text-white"
                                   x-text="scope === 'semua' ? card.station : card.metric.label"></p>
                                <p class="truncate text-[11px] text-mist-400"
                                   x-text="scope === 'semua'
                                       ? card.metric.label + (card.metric.unit ? ` (${card.metric.unit})` : '')
                                       : (card.metric.unit || '—')"></p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="tnum text-[15px] font-bold text-white">
                                    <span x-text="lastOf(card.id)"></span>
                                    <span class="text-[10.5px] font-medium text-mist-300" x-text="card.metric.unit ?? ''"></span>
                                </p>
                                {{-- The range suffix is dropped on a phone: with the
                                     station name beside it there is no room. --}}
                                <p class="tnum text-[10.5px] whitespace-nowrap" x-show="deltaOf(card.id) !== null"
                                   :class="deltaOf(card.id) > 0 ? 'text-state-waspada' : 'text-state-normal'">
                                    <span x-text="signed(deltaOf(card.id))"></span>
                                    <span class="max-sm:hidden" x-text="'/ ' + range"></span>
                                </p>
                            </div>
                        </div>

                        {{-- The station's own status comes from the server, not
                             from re-deriving thresholds in the browser. --}}
                        <div class="mb-1.5 flex items-center gap-2" x-show="scope === 'semua' && statusOf(card.code)">
                            <span class="status-dot" :style="`background:${window.statusColor(statusOf(card.code))}`"></span>
                            <span class="text-[10.5px] text-mist-300" x-text="statusOf(card.code)"></span>
                        </div>

                        <div class="relative h-[190px] w-full max-sm:h-[160px]">
                            <div class="h-full w-full" data-chart :data-card-id="card.id" x-init="observe($el)"></div>

                            {{-- An empty window is a fact about the data, not a
                                 chart that failed to draw: say which it is. --}}
                            <p class="absolute inset-0 grid place-items-center text-center text-[11px] text-mist-400"
                               x-show="data[card.id] && ! data[card.id].pending && ! data[card.id].points?.length" x-cloak
                               x-text="data[card.id]?.failed
                                   ? 'Seri gagal dimuat.'
                                   : `Tidak ada bacaan pada rentang ${range}.`"></p>
                        </div>
                    </button>
                </template>

                <p x-show="cards.length === 0"
                   class="glass glass--panel px-4 py-10 text-center text-[12px] text-mist-400">
                    Stasiun ini belum punya parameter terukur.
                </p>
            </div>

            {{-- Analisa: the parameters that were ticked, in one chart ----- --}}
            <div x-show="mode === 'analisa'" x-cloak class="glass glass--panel p-4" x-sheen>
                <div class="mb-3">
                    <div class="mb-2 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-[14px] font-semibold text-white">Parameter yang digabung</h2>
                            <p class="text-[11px] leading-relaxed text-mist-400">
                                Centang beberapa parameter untuk menumpuknya dalam satu grafik. Satuan
                                kedua dapat sumbu kanan sendiri; satuan ketiga tidak bisa ikut.
                            </p>
                        </div>

                        <button type="button"
                                class="flex items-center gap-1.5 text-[11.5px] text-mist-300 transition hover:text-white"
                                @click="backToGrid()">
                            <x-icon name="arrow-left" class="size-3.5"/>
                            Lihat semua grafik
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="choice in choices" :key="'pick' + choice.id">
                            <button type="button"
                                    class="rounded-xl px-3 py-1.5 text-[11.5px] font-medium transition"
                                    :aria-pressed="isPicked(choice)"
                                    :disabled="! canPick(choice)"
                                    :title="canPick(choice)
                                        ? (choice.metric.unit || 'tanpa satuan')
                                        : `Satuan ${choice.metric.unit || '—'} tidak bisa ikut: grafik sudah memakai dua sumbu`"
                                    :class="isPicked(choice)
                                        ? 'bg-brand-500/85 text-white'
                                        : (canPick(choice)
                                            ? 'bg-white/8 text-mist-200 hover:bg-white/16'
                                            : 'bg-white/4 text-mist-500')"
                                    @click="togglePick(choice)">
                                <span x-text="scope === 'semua' ? choice.station : choice.metric.label"></span>
                                <span class="text-[10px] opacity-70"
                                      x-text="choice.metric.unit ? ` (${choice.metric.unit})` : ''"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="relative h-[420px] w-full max-lg:h-[320px] max-sm:h-[260px]">
                    <div class="h-full w-full" data-chart x-ref="analysis"></div>

                    <p class="absolute inset-0 grid place-items-center text-center text-[12px] text-mist-400"
                       x-show="primary && ! loading && ! primary.points?.length" x-cloak
                       x-text="`Tidak ada bacaan pada rentang ${range}. Coba rentang yang lebih panjang.`"></p>
                </div>

                {{-- Statistics describe one series, so they follow the first
                     parameter picked and say so. --}}
                <p class="mt-3 text-[11px] text-mist-400" x-show="primary" x-cloak>
                    Statistik untuk
                    <span class="text-mist-200" x-text="primary?.metric?.label ?? ''"></span>
                    <span x-show="picked.length > 1" x-cloak>— parameter pertama yang dipilih</span>
                </p>

                <div class="mt-1.5 grid grid-cols-4 gap-3 max-sm:grid-cols-2">
                    <template x-for="stat in [
                        { label: 'Nilai Terakhir', value: primary?.points?.at(-1)?.v },
                        { label: 'Minimum', value: primary?.points?.length ? Math.min(...primary.points.map(p => p.v)) : null },
                        { label: 'Maksimum', value: primary?.points?.length ? Math.max(...primary.points.map(p => p.v)) : null },
                        { label: 'Rata-rata', value: primary?.points?.length
                            ? primary.points.reduce((sum, p) => sum + p.v, 0) / primary.points.length
                            : null },
                    ]" :key="stat.label">
                        <div class="glass glass--inset px-3.5 py-3">
                            <p class="text-[11px] text-mist-300" x-text="stat.label"></p>
                            <p class="tnum mt-1 text-[17px] font-bold text-white">
                                <span x-text="number(stat.value)"></span>
                                <span class="text-[11px] font-medium text-mist-300" x-text="primary?.metric?.unit ?? ''"></span>
                            </p>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </x-page-shell>
@endsection

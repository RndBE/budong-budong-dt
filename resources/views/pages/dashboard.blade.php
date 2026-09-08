@extends('layouts.app')

@section('title', 'Dashboard')

@section('stage')
    <x-page-shell title="Dashboard Operasi" subtitle="Ikhtisar kondisi bendungan, instrumentasi, dan peringatan hari ini.">
        {{-- The board and the summary column beside it are arranged together:
             they are one screen to the reader, and ordering half of it would be
             half a feature. The order, the widths and what each card shows come
             from the server; the browser only edits them, and only a role
             holding `dashboard.arrange` sees the controls at all. --}}
        @can('dashboard.arrange')
            <x-slot:actions>
                {{-- With the page's other actions rather than on a row of its
                     own above the board. It is opened a few times a year; a
                     full row of the screen spent on it every day is a row the
                     numbers do not get. --}}
                <button type="button" class="glass glass--chip glass-button px-4 py-2.5 text-[12.5px] font-semibold"
                        x-init="$store.arrange.boot(@js($layouts), @js($choices))"
                        :class="$store.arrange.editing && 'text-brand-300 ring-1 ring-brand-400/60'"
                        :aria-pressed="$store.arrange.editing"
                        @click="$store.arrange.editing ? $store.arrange.cancel() : $store.arrange.open()">
                    <x-icon name="layers" class="size-4"/>
                    <span x-text="$store.arrange.editing ? 'Batalkan penataan' : 'Atur tata letak'"></span>
                </button>
            </x-slot:actions>

            {{-- The rest of the arranging only exists while arranging, so no
                 row is spent on it the rest of the time. --}}
            <div class="mb-3.5" x-show="$store.arrange.editing" x-cloak>
                <div class="flex flex-wrap items-center gap-2">
                        <button type="button"
                                class="glass glass--chip glass-button px-3.5 py-2 text-[12px] font-semibold text-white"
                                :disabled="$store.arrange.saving" @click="$store.arrange.save()">
                            <span x-text="$store.arrange.saving ? 'Menyimpan…' : 'Simpan untuk semua'"></span>
                        </button>

                        <button type="button"
                                class="glass glass--chip glass-button px-3.5 py-2 text-[12px] font-semibold text-mist-200"
                                :disabled="$store.arrange.saving" @click="$store.arrange.reset()">
                            Kembalikan ke bawaan
                        </button>

                        @foreach (\App\Support\DashboardLayout::PRESETS as $slug => $preset)
                            <button type="button"
                                    class="glass glass--chip glass-button px-3 py-2 text-[11.5px] font-semibold text-mist-200"
                                    title="{{ $preset['hint'] }}"
                                    :disabled="$store.arrange.saving"
                                    @click="$store.arrange.usePreset(@js($preset['board']), @js($preset['panel']))">
                                {{ $preset['label'] }}
                            </button>
                        @endforeach

                        <p class="text-[11px] text-mist-400">
                            Berlaku untuk papan ini dan kolom ringkasan di kanan.
                            Seret kartu papan, atau pakai tombol panah pada tiap kartu.
                            Tersimpan untuk seluruh ruang kendali.
                        </p>
                </div>

                <p class="mt-2 text-[11px] text-state-bahaya" x-show="$store.arrange.error" x-cloak
                   x-text="$store.arrange.error"></p>
            </div>

            {{-- What a card shows. A dialog rather than controls wedged into the
                 card: one of these offers a hundred and forty-six parameters,
                 and a `<select multiple>` that deep is a list nobody reads and a
                 Ctrl-click nobody discovers. Teleported for the reason every
                 dialog here is — the page sits in a transformed stacking
                 context. --}}
            <template x-teleport="body">
                <div x-show="$store.arrange.optionsFor" x-cloak x-transition.opacity.duration.150ms
                     class="modal-scrim" @click.self="$store.arrange.closeOptions()"
                     @keydown.escape.window="$store.arrange.optionsFor && $store.arrange.closeOptions()">
                    <div class="modal-card glass glass--panel glass--menu p-4"
                         x-show="$store.arrange.optionsFor" x-transition
                         role="dialog" aria-modal="true" aria-labelledby="dash-opts-title">

                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div>
                                <h3 id="dash-opts-title" class="text-[15px] font-semibold text-white">
                                    Isi kartu · <span x-text="$store.arrange.cardLabel"></span>
                                </h3>
                                <p class="mt-0.5 text-[11px] text-mist-400">
                                    Pilihannya berasal dari instrumentasi yang benar-benar terpasang.
                                    Kosongkan untuk memakai bawaan.
                                </p>
                            </div>
                            <button type="button" class="glass glass--chip glass-button size-9 shrink-0"
                                    title="Tutup" aria-label="Tutup pengaturan isi"
                                    @click="$store.arrange.closeOptions()">
                                <x-icon name="x" class="size-4"/>
                            </button>
                        </div>

                        <template x-for="(spec, name) in $store.arrange.openChoices" :key="'opt' + name">
                            <div class="mb-3 last:mb-0">
                                <p class="mb-1.5 text-[11.5px] font-semibold text-mist-200" x-text="spec.label"></p>

                                {{-- One of many: chips for what is picked, a field
                                     to find the rest, and the list underneath. --}}
                                <template x-if="spec.multiple">
                                    <div>
                                        <div class="mb-2 flex flex-wrap gap-1.5" x-show="$store.arrange.picks(name).length">
                                            <template x-for="value in $store.arrange.picks(name)" :key="'chip' + value">
                                                <button type="button"
                                                        class="glass glass--inset flex items-center gap-1.5 px-2.5 py-1 text-[11px] text-white"
                                                        :aria-label="'Hapus ' + spec.choices[value]"
                                                        @click="$store.arrange.togglePick(name, value, spec.max)">
                                                    <span x-text="spec.choices[value]"></span>
                                                    <x-icon name="x" class="size-3"/>
                                                </button>
                                            </template>
                                        </div>

                                        <label class="glass glass--inset mb-2 flex items-center gap-2 px-3 py-2">
                                            <x-icon name="search" class="size-4 shrink-0 text-mist-400"/>
                                            <input type="search" class="w-full bg-transparent text-[12px] text-white outline-none"
                                                   placeholder="Cari stasiun atau parameter…"
                                                   aria-label="Cari parameter"
                                                   x-model="$store.arrange.search">
                                        </label>

                                        <p class="mb-1.5 text-[10.5px] text-mist-400">
                                            <span x-text="$store.arrange.picks(name).length"></span> dari
                                            <span x-text="spec.max"></span> terpilih.
                                            <span x-show="$store.arrange.picks(name).length >= spec.max">
                                                Hapus satu sebelum menambah yang lain.
                                            </span>
                                        </p>

                                        <div class="scroll-y max-h-[46dvh] pr-1">
                                            <template x-for="group in $store.arrange.grouped(spec)" :key="'grp' + group.station">
                                                <div class="mb-2">
                                                    <p class="mb-1 text-[10.5px] font-semibold tracking-wide text-brand-300 uppercase"
                                                       x-text="group.station"></p>
                                                    <div class="grid gap-1">
                                                        <template x-for="row in group.rows" :key="'row' + row.value">
                                                            <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-[12px] text-mist-100 hover:bg-white/6">
                                                                <input type="checkbox"
                                                                       :checked="$store.arrange.isPicked(name, row.value)"
                                                                       :disabled="!$store.arrange.isPicked(name, row.value) && $store.arrange.picks(name).length >= spec.max"
                                                                       @change="$store.arrange.togglePick(name, row.value, spec.max)">
                                                                <span x-text="row.label"></span>
                                                            </label>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>

                                            <p class="px-2 py-3 text-[11.5px] text-mist-400"
                                               x-show="!$store.arrange.grouped(spec).length">
                                                Tidak ada yang cocok dengan pencarian itu.
                                            </p>
                                        </div>
                                    </div>
                                </template>

                                {{-- One of a few: a plain list. --}}
                                <template x-if="!spec.multiple">
                                    <select class="glass glass--inset w-full appearance-none px-3 py-2 text-[12.5px] text-white"
                                            @change="$store.arrange.setOption(name, $event.target.value)">
                                        <template x-for="(label, value) in spec.choices" :key="'ch' + value">
                                            <option class="bg-ink-800" :value="value" x-text="label"
                                                    :selected="($store.arrange.optionOf(name) ?? '') === value"></option>
                                        </template>
                                    </select>
                                </template>
                            </div>
                        </template>

                        <p class="mt-3 text-[11px] text-mist-400">
                            Perubahan berlaku setelah <span class="font-semibold text-mist-200">Simpan untuk semua</span>.
                        </p>
                    </div>
                </div>
            </template>
        @endcan

        <div class="dash-grid pb-2" :class="$store.arrange.editing && 'dash-grid--editing'">
            @foreach ($layouts['board'] as $card)
                <div class="dash-card{{ $card['hidden'] ? ' dash-card--off' : '' }}"
                     data-card="{{ $card['key'] }}"
                     @if ($card['fixed']) data-fixed="1" @endif
                     style="--span: {{ $card['span'] }}"
                     :draggable="$store.arrange.editing && '{{ $card['fixed'] ? '' : '1' }}' === '1'"
                     @dragstart="$store.arrange.lift($event, '{{ $card['key'] }}')"
                     @dragover.prevent="$store.arrange.hover('{{ $card['key'] }}')"
                     @dragend="$store.arrange.dragging = null">

                    @can('dashboard.arrange')
                        {{-- The bar is only in the document while arranging, so
                             nothing of it can be tabbed into on the ordinary board. --}}
                        <template x-if="$store.arrange.editing">
                            <div class="dash-card__bar">
                                <span class="truncate text-[11.5px] font-semibold text-white">{{ $card['label'] }}</span>

                                <span class="ml-auto flex items-center gap-1">
                                    <button type="button" class="dash-card__act" title="Naikkan"
                                            aria-label="Naikkan {{ $card['label'] }}"
                                            @click="$store.arrange.move('board', '{{ $card['key'] }}', -1)">
                                        <x-icon name="arrow-up" class="size-3.5"/>
                                    </button>
                                    <button type="button" class="dash-card__act" title="Turunkan"
                                            aria-label="Turunkan {{ $card['label'] }}"
                                            @click="$store.arrange.move('board', '{{ $card['key'] }}', 1)">
                                        <x-icon name="arrow-down" class="size-3.5"/>
                                    </button>

                                    @if (! empty($choices['board'][$card['key']]))
                                        <button type="button" class="dash-card__act px-2 text-[10.5px] font-semibold"
                                                title="Atur isi {{ $card['label'] }}"
                                                @click="$store.arrange.openOptions('board', '{{ $card['key'] }}')">
                                            Isi
                                        </button>
                                    @endif

                                    @unless ($card['fixed'])
                                        <label class="dash-card__act px-1.5">
                                            <span class="sr-only">Lebar {{ $card['label'] }}</span>
                                            <select class="bg-transparent text-[11px] text-white outline-none"
                                                    @change="$store.arrange.setSpan('board', '{{ $card['key'] }}', $event.target.value)">
                                                @foreach (\App\Support\DashboardLayout::SPANS as $value => $label)
                                                    <option class="bg-ink-800" value="{{ $value }}"
                                                            @selected($card['span'] === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <button type="button" class="dash-card__act" title="Sembunyikan"
                                                :aria-pressed="$store.arrange.isHidden('board', '{{ $card['key'] }}')"
                                                aria-label="Sembunyikan {{ $card['label'] }}"
                                                @click="$store.arrange.toggleHidden('board', '{{ $card['key'] }}')">
                                            <x-icon name="eye" class="size-3.5"/>
                                        </button>
                                    @endunless
                                </span>
                            </div>
                        </template>
                    @endcan

                    @include('partials.dashboard.' . $card['key'])
                </div>
            @endforeach
        </div>
    </x-page-shell>
@endsection

@section('panel')
    @include('partials.right.summary', ['skipPrimary' => true])
@endsection

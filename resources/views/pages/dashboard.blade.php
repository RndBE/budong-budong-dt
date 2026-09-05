@extends('layouts.app')

@section('title', 'Dashboard')

@section('stage')
    <x-page-shell title="Dashboard Operasi" subtitle="Ikhtisar kondisi bendungan, instrumentasi, dan peringatan hari ini.">
        <x-slot:actions>
            <a href="{{ route('twin') }}" class="glass glass--chip glass-button px-4 py-2.5 text-[12.5px] font-semibold">
                <x-icon name="cube" class="size-4"/>
                Buka Digital Twin
            </a>
        </x-slot:actions>

        {{-- Headline numbers --}}
        <div class="grid grid-cols-4 gap-3.5 max-lg:grid-cols-2">
            <template x-for="tile in ($store.site.dashboard?.primary ?? [])" :key="'kpi' + tile.metric + tile.station">
                <div class="glass glass--panel p-4" x-sheen>
                    <p class="text-[11.5px] font-medium text-mist-300" x-text="tile.label"></p>
                    <p class="mt-2 flex items-baseline gap-1.5">
                        <span class="tnum text-[28px] leading-none font-extrabold"
                              :style="`color:${window.statusColor(tile.status)}`"
                              x-text="tile.formatted"></span>
                        <span class="text-[12px] text-mist-300" x-text="tile.unit"></span>
                    </p>
                    <p class="mt-2 flex items-center gap-2 text-[11px] text-mist-400">
                        <template x-if="tile.trend">
                            <span class="font-semibold"
                                  :class="tile.trend.direction === 'up' ? 'text-state-normal' : 'text-brand-300'"
                                  x-text="(tile.trend.direction === 'up' ? '↑ ' : '↓ ') + tile.trend.formatted"></span>
                        </template>
                        <span class="tnum" x-text="tile.recorded_label ?? tile.note ?? ''"></span>
                    </p>
                </div>
            </template>
        </div>

        {{-- Trend + station status --}}
        <div class="mt-3.5 grid grid-cols-3 gap-3.5 max-lg:grid-cols-1">
            <div class="glass glass--panel col-span-2 p-4 max-lg:col-span-1" x-sheen
                 x-data="analyticsBoard(@js($stationsForChart ?? []))">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-[14px] font-semibold text-white">Tren Muka Air &amp; Debit</h2>
                        <p class="text-[11.5px] text-mist-400">Sumber: AWLR Hulu · pilih rentang untuk memperbarui</p>
                    </div>
                    <div class="glass glass--inset flex items-center gap-0.5 p-0.5">
                        <template x-for="option in ['24h', '7d', '30d']" :key="'r' + option">
                            <button type="button" class="rounded-xl px-2.5 py-1 text-[10.5px] font-semibold transition"
                                    :class="range === option ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                    @click="range = option; load()" x-text="option"></button>
                        </template>
                    </div>
                </div>
                <div class="h-[248px] w-full" data-chart x-ref="chart"></div>
            </div>

            <div class="glass glass--panel p-4" x-sheen>
                <h2 class="mb-3 text-[14px] font-semibold text-white">Status Stasiun</h2>
                <ul class="scroll-y max-h-[276px] space-y-1.5 pr-1">
                    <template x-for="marker in $store.site.markers" :key="'st' + marker.code">
                        <li>
                            <a :href="`/digital-twin/${marker.code}`"
                               class="glass glass--inset flex items-center gap-2.5 px-3 py-2 transition hover:bg-white/8">
                                <span class="status-dot" :style="`background:${window.statusColor(marker.status)}`"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[12px] font-medium text-mist-100" x-text="marker.short_name"></span>
                                    <span class="block truncate text-[10.5px] text-mist-400" x-text="marker.type_label"></span>
                                </span>
                                <span class="tnum shrink-0 text-[11px] text-mist-200" x-text="marker.caption"></span>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        {{-- Alerts + maintenance --}}
        <div class="mt-3.5 grid grid-cols-2 gap-3.5 pb-2 max-lg:grid-cols-1">
            <div class="glass glass--panel p-4" x-sheen>
                <div class="mb-2.5 flex items-center justify-between">
                    <h2 class="text-[14px] font-semibold text-white">Peringatan Terbaru</h2>
                    <a href="{{ route('alerts') }}" class="text-[11px] font-medium text-brand-300">Semua</a>
                </div>
                <ul class="space-y-2.5">
                    <template x-for="alert in ($store.site.dashboard?.alerts ?? [])" :key="'da' + alert.id">
                        <li class="flex gap-2.5">
                            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-xl"
                                  :style="`background:${window.statusColor(alert.level)}22;color:${window.statusColor(alert.level)}`">
                                <x-icon name="warning" class="size-4"/>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-[12.5px] font-semibold text-white" x-text="alert.title"></p>
                                <p class="truncate text-[11px] text-mist-300" x-text="alert.message"></p>
                                <p class="tnum text-[10.5px] text-mist-400" x-text="alert.triggered_label"></p>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="glass glass--panel p-4" x-sheen>
                <div class="mb-2.5 flex items-center justify-between">
                    <h2 class="text-[14px] font-semibold text-white">Perawatan Mendatang</h2>
                    <a href="{{ route('maintenance') }}" class="text-[11px] font-medium text-brand-300">Semua</a>
                </div>
                <ul class="space-y-2">
                    @foreach ($upcoming as $task)
                        <li class="glass glass--inset flex items-center gap-2.5 px-3 py-2">
                            <x-icon name="wrench" class="size-4 shrink-0 text-mist-400"/>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[12px] font-medium text-mist-100">{{ $task->title }}</span>
                                <span class="block truncate text-[10.5px] text-mist-400">
                                    {{ $task->station?->short_name ?? $task->station?->name }} · {{ $task->assignee }}
                                </span>
                            </span>
                            <span class="tnum shrink-0 text-[11px] text-mist-200">
                                {{ $task->scheduled_for->translatedFormat('d M') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </x-page-shell>
@endsection

@section('panel')
    @include('partials.right.summary', ['skipPrimary' => true])
@endsection

@extends('layouts.app')

@section('title', 'Perawatan')

@section('stage')
    <x-page-shell wide title="Perawatan Instrumentasi"
                  subtitle="Jadwal preventif, korektif, dan kalibrasi perangkat lapangan.">

        <div class="grid grid-cols-4 gap-3.5 max-xl:grid-cols-2 max-sm:grid-cols-1" x-data="{
            async move(id, status) {
                await fetch(`/api/maintenance/${id}/status`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ status }),
                });
                window.location.reload();
            }
        }">
            @foreach ($columns as $key => $label)
                @php($items = $tasks->where('status', $key))
                <div class="glass glass--panel flex flex-col p-3.5" x-sheen>
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-[13.5px] font-semibold text-white">{{ $label }}</h2>
                        <span class="tnum rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-mist-200">
                            {{ $items->count() }}
                        </span>
                    </div>

                    <ul class="scroll-y max-h-[calc(100vh-260px)] space-y-2.5 pr-1">
                        @foreach ($items as $task)
                            <li class="glass glass--inset p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-[12.5px] leading-snug font-semibold text-white">{{ $task->title }}</p>
                                    <span class="shrink-0 rounded-lg px-1.5 py-0.5 text-[10px] font-bold uppercase
                                        @class([
                                            'bg-state-bahaya/20 text-state-bahaya' => $task->priority === 'tinggi',
                                            'bg-white/10 text-mist-200' => $task->priority === 'normal',
                                            'bg-white/6 text-mist-400' => $task->priority === 'rendah',
                                        ])">
                                        {{ $task->priority }}
                                    </span>
                                </div>

                                <p class="mt-1.5 text-[11px] text-mist-300">
                                    {{ $task->station?->name ?? 'Umum' }}
                                </p>

                                <div class="mt-2 flex items-center gap-2 text-[10.5px] text-mist-400">
                                    <x-icon name="calendar" class="size-3.5"/>
                                    <span class="tnum">{{ $task->scheduled_for->translatedFormat('d M Y') }}</span>
                                    <span>·</span>
                                    <span>{{ ucfirst($task->type) }}</span>
                                </div>

                                <p class="mt-1 flex items-center gap-1.5 text-[10.5px] text-mist-400">
                                    <x-icon name="user" class="size-3.5"/>
                                    {{ $task->assignee }}
                                </p>

                                @if ($task->notes)
                                    <p class="mt-2 rounded-lg bg-white/6 px-2 py-1.5 text-[10.5px] text-mist-300">
                                        {{ $task->notes }}
                                    </p>
                                @endif

                                <div class="mt-2.5 flex flex-wrap gap-1.5">
                                    @foreach (array_diff(array_keys($columns), [$key]) as $target)
                                        <button type="button"
                                                class="rounded-lg bg-white/8 px-2 py-1 text-[10.5px] font-medium text-mist-200 transition hover:bg-white/16 hover:text-white"
                                                @click="move({{ $task->id }}, '{{ $target }}')">
                                            → {{ $columns[$target] }}
                                        </button>
                                    @endforeach
                                </div>
                            </li>
                        @endforeach

                        @if ($items->isEmpty())
                            <li class="rounded-xl border border-dashed border-white/12 px-3 py-6 text-center text-[11.5px] text-mist-400">
                                Tidak ada pekerjaan.
                            </li>
                        @endif
                    </ul>
                </div>
            @endforeach
        </div>
    </x-page-shell>
@endsection

@section('panel')
    <div></div>
@endsection

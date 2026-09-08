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

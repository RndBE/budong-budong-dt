        <div class="glass glass--panel p-4" x-sheen
             x-data="{ get list() { return $store.site.urgentMarkers(@js($card['options']['type'] ?? '')) } }">
            <div class="mb-3 flex items-baseline justify-between gap-2">
                <h2 class="text-[14px] font-semibold text-white">Status Stasiun</h2>
                <span class="tnum text-[10.5px] text-mist-400">
                    <span x-text="list.worst.length + list.calm.length"></span> stasiun
                </span>
            </div>

            {{-- Worst first, and the ones with nothing to report collapsed into
                 a single line. Seventeen stations that are all fine is one
                 fact, not seventeen rows to scroll past. --}}
            <ul class="scroll-y max-h-[276px] space-y-1.5 pr-1">
                <template x-for="marker in list.worst" :key="'st' + marker.code">
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

                <template x-if="!list.worst.length">
                    <li class="glass glass--inset flex items-center gap-2.5 px-3 py-2.5">
                        <x-icon name="check" class="size-4 shrink-0 text-state-normal"/>
                        <span class="text-[12px] text-mist-100">Semua stasiun normal.</span>
                    </li>
                </template>

                <template x-if="list.calm.length">
                    <li>
                        <details class="glass glass--inset px-3 py-2">
                            <summary class="cursor-pointer text-[11.5px] text-mist-300">
                                <span x-text="list.calm.length"></span> lainnya normal
                            </summary>
                            <ul class="mt-2 space-y-1.5">
                                <template x-for="marker in list.calm" :key="'calm' + marker.code">
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
                        </details>
                    </li>
                </template>
            </ul>
        </div>

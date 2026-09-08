        <div class="glass glass--panel p-4" x-sheen>
            <div class="mb-2.5 flex items-center justify-between">
                <h2 class="text-[14px] font-semibold text-white">Riwayat Peringatan</h2>
                <a href="{{ route('alerts') }}" class="text-[11px] font-medium text-brand-300">Semua</a>
            </div>
            <ul class="space-y-2.5">
                <template x-for="alert in ($store.site.dashboard?.alert_history ?? []).slice(0, @js((int) ($card['options']['limit'] ?? 5)))"
                          :key="'da' + alert.id">
                    <li class="flex gap-2.5">
                        <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-xl"
                              :style="`background:${window.statusColor(alert.level)}22;color:${window.statusColor(alert.level)}`">
                            <x-icon name="warning" class="size-4"/>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-[12.5px] font-semibold text-white" x-text="alert.title"></p>
                            <p class="truncate text-[11px] text-mist-300" x-text="alert.message"></p>
                            <p class="tnum text-[10.5px] text-mist-400">
                                <span x-text="alert.triggered_label"></span>
                                {{-- Settled or standing: the difference is the
                                     whole reason this list is not the one down
                                     the right of the page. --}}
                                <span x-show="alert.is_resolved" class="text-state-normal"> · selesai</span>
                            </p>
                        </div>
                    </li>
                </template>
            </ul>
        </div>

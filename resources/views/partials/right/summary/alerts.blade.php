    {{-- Peringatan Aktif ----------------------------------------------- --}}
    <div class="glass glass--panel panel-enter shrink-0 p-4" x-sheen>
        <div class="mb-2.5 flex items-center justify-between">
            <h2 class="text-[14px] font-semibold text-white">Peringatan Aktif</h2>
            <a href="{{ route('alerts') }}" class="text-[11px] font-medium text-brand-300 hover:text-brand-200">Lihat Semua</a>
        </div>

        <ul class="space-y-2.5">
            <template x-for="alert in ($store.site.dashboard?.alerts ?? [])" :key="alert.id">
                <li class="flex gap-2.5">
                    <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-xl"
                          :style="`background:${window.statusColor(alert.level)}22;color:${window.statusColor(alert.level)}`">
                        <x-icon name="warning" class="size-4"/>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[12.5px] font-semibold text-white" x-text="alert.title"></p>
                        <p class="truncate text-[11px] text-mist-300" x-text="alert.message"></p>
                        <p class="tnum mt-0.5 text-[10.5px] text-mist-400" x-text="alert.triggered_label"></p>
                    </div>
                    <button type="button" class="self-start rounded-lg px-1.5 py-1 text-mist-400 transition hover:bg-white/8 hover:text-white"
                            title="Tandai selesai"
                            @click="fetch(`/api/alerts/${alert.id}/resolve`, {method:'POST', headers:{'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}}).then(() => $store.site.refreshDashboard())">
                        <x-icon name="check" class="size-3.5"/>
                    </button>
                </li>
            </template>

            <template x-if="($store.site.dashboard?.alerts ?? []).length === 0">
                <li class="flex items-center gap-2 rounded-xl bg-white/5 px-3 py-3 text-[12px] text-mist-300">
                    <x-icon name="check" class="size-4 text-state-normal"/>
                    Tidak ada peringatan aktif.
                </li>
            </template>
        </ul>
    </div>

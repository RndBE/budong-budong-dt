@php($compact = $compact ?? false)

{{-- Station search. Lives in the header next to the clock rather than floating
     over the panorama: it belongs to the whole app, not to one stage. On the
     digital twin it turns the camera to the pin; anywhere else it opens that
     station's panorama. --}}
<div class="pointer-events-auto relative {{ $compact ? 'md:hidden' : 'max-md:hidden' }}"
     x-data="stationSearch()"
     @keydown.escape.stop="close()"
     @click.outside="close()">

    @if ($compact)
        <button type="button" class="glass glass--chip glass-button size-11"
                title="Cari lokasi" aria-label="Cari lokasi"
                :aria-expanded="open"
                @click="toggle()">
            <x-icon name="search" class="size-5"/>
        </button>
    @else
        <div class="glass glass--chip flex items-center gap-2.5 px-3.5 py-2.5" x-sheen>
            <x-icon name="search" class="size-4 shrink-0 text-mist-300"/>
            <input type="search" x-ref="field" placeholder="Cari lokasi"
                   class="w-[150px] bg-transparent text-[13px] text-white placeholder:text-mist-300"
                   aria-label="Cari lokasi atau stasiun"
                   x-model="query"
                   @focus="open = true"
                   @keydown.enter.prevent="pick(results[0])">
            <kbd class="rounded-md border border-white/12 px-1.5 py-0.5 text-[10px] font-semibold text-mist-400"
                 aria-hidden="true">/</kbd>
        </div>
    @endif

    <div x-show="open && (query.length > 0 || {{ $compact ? 'true' : 'false' }})" x-cloak x-transition.origin.top
         class="glass glass--panel glass--menu absolute z-10 mt-2 w-[290px] p-1.5
                {{ $compact ? 'right-0' : 'left-0' }}">

        @if ($compact)
            <div class="glass glass--inset mb-1.5 flex items-center gap-2.5 px-3 py-2">
                <x-icon name="search" class="size-4 shrink-0 text-mist-300"/>
                <input type="search" x-ref="field" placeholder="Cari lokasi"
                       class="w-full bg-transparent text-[13px] text-white placeholder:text-mist-300"
                       aria-label="Cari lokasi atau stasiun"
                       x-model="query"
                       @keydown.enter.prevent="pick(results[0])">
            </div>
        @endif

        <div class="max-h-[300px] overflow-y-auto">
            <template x-for="marker in results" :key="marker.code">
                <button type="button" class="nav-item w-full text-left" @click="pick(marker)">
                    <span class="status-dot shrink-0" :style="`background:${window.statusColor(marker.status)}`"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[12.5px] text-mist-100" x-text="marker.name"></span>
                        <span class="block truncate text-[10.5px] text-mist-400"
                              x-text="marker.type_label + (marker.zone ? ' · ' + marker.zone : '')"></span>
                    </span>
                    <x-icon name="chevron-right" class="size-3.5 shrink-0 text-mist-400"/>
                </button>
            </template>

            <p class="px-3 py-3 text-[11.5px] text-mist-400" x-show="query.length > 0 && results.length === 0">
                Lokasi tidak ditemukan.
            </p>

            <p class="px-3 py-3 text-[11.5px] text-mist-400" x-show="query.length === 0">
                Ketik nama stasiun, kode, atau zona.
            </p>
        </div>
    </div>
</div>

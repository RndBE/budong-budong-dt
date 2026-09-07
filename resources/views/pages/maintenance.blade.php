@extends('layouts.app')

@section('title', 'Perawatan')

@section('stage')
    {{-- The maintenance desk: ask for work, talk it through with the service
         desk, and keep the record of what was done to each instrument. --}}
    <x-page-shell wide title="Perawatan Instrumentasi"
                  subtitle="Permintaan, percakapan dengan layanan teknis, dan riwayat perawatan alat.">

        <div x-data="maintenanceDesk(@js($tickets), @js($history), @js($desk))"
             @keydown.escape.window="closeRequest()">

            <x-slot:actions></x-slot:actions>

            {{-- Tabs + the request button --}}
            <div class="mb-3.5 flex flex-wrap items-center justify-between gap-2.5">
                <div class="glass glass--chip flex items-center gap-1 p-1.5" role="tablist" aria-label="Bagian perawatan">
                    @foreach ([
                        'pesan' => ['label' => 'Tiket & Pesan', 'icon' => 'message'],
                        'papan' => ['label' => 'Papan Tugas', 'icon' => 'clipboard-check'],
                        'riwayat' => ['label' => 'Riwayat', 'icon' => 'history'],
                    ] as $key => $meta)
                        <button type="button" role="tab"
                                id="desk-tab-{{ $key }}" aria-controls="desk-panel-{{ $key }}"
                                class="flex items-center gap-2 rounded-xl px-4 py-2 text-[12.5px] font-semibold transition"
                                :aria-selected="tab === '{{ $key }}'"
                                :class="tab === '{{ $key }}'
                                    ? 'bg-brand-500/90 text-white shadow-[0_10px_24px_-12px_rgba(31,139,245,.9)]'
                                    : 'text-mist-200 hover:bg-white/8'"
                                @click="tab = '{{ $key }}'">
                            <x-icon :name="$meta['icon']" class="size-4"/>
                            {{ $meta['label'] }}
                            @if ($key === 'pesan')
                                <span x-show="$store.site.maintenanceUnread > 0" x-cloak
                                      class="tnum rounded-full bg-state-bahaya px-1.5 py-0.5 text-[10px] font-bold text-white"
                                      x-text="$store.site.maintenanceUnread"></span>
                            @endif
                        </button>
                    @endforeach
                </div>

                @can('maintenance.request')
                    <button type="button" class="glass glass--chip glass-button gap-2 px-4 py-2.5 text-[12.5px] font-semibold"
                            @click="openRequest()">
                        <x-icon name="plus" class="size-4"/>
                        Ajukan perawatan
                    </button>
                @endcan
            </div>

            {{-- New request: a dialog, so asking for work does not push the
                 desk down the page. Teleported to <body> because the page
                 content sits in a transformed, z-20 stacking context. --}}
            @can('maintenance.request')
            <template x-teleport="body">
                <div x-show="form.open" x-cloak x-transition.opacity.duration.150ms
                     class="modal-scrim" @click.self="closeRequest()">
                    <div class="modal-card modal-card--wide glass glass--panel glass--menu"
                         x-show="form.open" x-transition
                         role="dialog" aria-modal="true" aria-labelledby="request-title">

                        <div class="flex items-start justify-between gap-3 border-b border-white/10 px-4 py-3">
                            <div>
                                <h2 id="request-title" class="text-[14px] font-semibold text-white">
                                    Permintaan perawatan baru
                                </h2>
                                <p class="mt-0.5 text-[11.5px] text-mist-300">
                                    Permintaan langsung terkirim ke layanan teknis dan membuka percakapan.
                                </p>
                            </div>
                            <button type="button" class="glass glass--chip glass-button size-9 shrink-0"
                                    title="Tutup" aria-label="Tutup" @click="closeRequest()">
                                <x-icon name="x" class="size-4"/>
                            </button>
                        </div>

                        <div class="scroll-y max-h-[62vh] px-4 py-4">
                            <div class="grid gap-2.5 md:grid-cols-2">
                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Judul pekerjaan</span>
                                    <input type="text" x-model="form.title" maxlength="160"
                                           placeholder="Mis. Kamera spillway berkabut"
                                           class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white placeholder:text-mist-400">
                                </label>

                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Alat / stasiun</span>
                                    <select x-model="form.station"
                                            class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white">
                                        <option value="">Umum (bukan satu alat)</option>
                                        @foreach ($desk['stations'] as $station)
                                            <option value="{{ $station['code'] }}">{{ $station['name'] }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Jenis</span>
                                    <select x-model="form.type" class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white">
                                        <option value="korektif">Korektif — ada yang rusak</option>
                                        <option value="preventif">Preventif — perawatan rutin</option>
                                        <option value="kalibrasi">Kalibrasi</option>
                                        <option value="inspeksi">Inspeksi</option>
                                    </select>
                                </label>

                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Prioritas</span>
                                    <select x-model="form.priority" class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white">
                                        <option value="rendah">Rendah</option>
                                        <option value="normal">Normal</option>
                                        <option value="tinggi">Tinggi</option>
                                    </select>
                                </label>

                                <label class="block md:col-span-2">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Keterangan</span>
                                    <textarea x-model="form.body" rows="4" maxlength="2000"
                                              placeholder="Ceritakan gejalanya, sejak kapan, dan apa yang sudah dicoba."
                                              class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white placeholder:text-mist-400"></textarea>
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-white/10 px-4 py-3">
                            <p class="mr-auto text-[11.5px] text-state-bahaya" x-show="error" x-cloak x-text="error"></p>
                            <button type="button" class="text-[12px] text-mist-300 transition hover:text-white"
                                    @click="closeRequest()">Batal</button>
                            <button type="button" class="glass glass--chip glass-button px-4 py-2.5 text-[12.5px] font-semibold"
                                    :disabled="sending || !form.title.trim()" @click="submitRequest()">
                                <x-icon name="send" class="size-4"/>
                                <span x-text="sending ? 'Mengirim…' : 'Kirim permintaan'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
            @endcan

            {{-- Tickets and conversation.

                 The two panes keep their own height and scroll inside it: a
                 chat that grows the page is a chat whose composer walks off
                 the bottom of the screen. --}}
            <div x-show="tab === 'pesan'" id="desk-panel-pesan" role="tabpanel" aria-labelledby="desk-tab-pesan"
                 class="grid gap-3.5 lg:h-[calc(100dvh-var(--stage-top)-176px)] lg:min-h-[520px] lg:grid-cols-[330px_minmax(0,1fr)]">

                {{-- `min-w-0` or the list refuses to shrink: `truncate` needs a
                     constrained parent, and without it the ticket buttons grow to
                     fit the longest preview and hang out of the panel. --}}
                <div class="glass glass--panel flex min-h-0 min-w-0 flex-col overflow-hidden p-3" x-sheen>
                    <div class="glass glass--inset mb-2.5 flex shrink-0 items-center gap-0.5 p-0.5">
                        @foreach (['aktif' => 'Aktif', 'riwayat' => 'Selesai', 'semua' => 'Semua'] as $key => $label)
                            <button type="button" class="flex-1 rounded-lg px-2 py-1.5 text-[11px] font-semibold transition"
                                    :class="scope === '{{ $key }}' ? 'bg-brand-500/85 text-white' : 'text-mist-300 hover:text-white'"
                                    @click="scope = '{{ $key }}'">{{ $label }}</button>
                        @endforeach
                    </div>

                    <ul class="scroll-y min-h-0 min-w-0 flex-1 space-y-1.5 pr-1 max-lg:max-h-[320px]">
                        <template x-for="ticket in visible" :key="ticket.id">
                            <li>
                                <button type="button" class="w-full rounded-2xl px-3 py-2.5 text-left transition"
                                        :class="ticket.id === activeId ? 'bg-brand-500/20 ring-1 ring-brand-400/40' : 'hover:bg-white/6'"
                                        @click="open(ticket.id)">
                                    <span class="flex items-start justify-between gap-2">
                                        <span class="min-w-0">
                                            <span class="block truncate text-[12.5px] font-semibold text-white" x-text="ticket.title"></span>
                                            <span class="block truncate text-[10.5px] text-mist-400"
                                                  x-text="(ticket.station ?? 'Umum') + ' · ' + ticket.type_label"></span>
                                        </span>
                                        <span class="flex shrink-0 flex-col items-end gap-1">
                                            <span class="tnum text-[10px] text-mist-400" x-text="previewTime(ticket)"></span>
                                            <span x-show="ticket.unread > 0"
                                                  class="tnum rounded-full bg-state-bahaya px-1.5 py-0.5 text-[10px] font-bold text-white"
                                                  x-text="ticket.unread"></span>
                                        </span>
                                    </span>

                                    {{-- The newest word, not a message count: it is
                                         what tells the reader whether to open it. --}}
                                    <span class="mt-1 block truncate text-[11px]"
                                          :class="ticket.unread > 0 ? 'text-mist-100' : 'text-mist-400'"
                                          x-text="preview(ticket)"></span>

                                    <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10px]">
                                        <span x-show="ticket.requester_id && ticket.requester_id === desk.user_id"
                                              class="rounded-md bg-brand-500/20 px-1.5 py-0.5 font-semibold text-brand-200">
                                            Permintaan Anda
                                        </span>
                                        <span class="rounded-md px-1.5 py-0.5 font-semibold uppercase"
                                              :class="{
                                                  'bg-state-normal/20 text-state-normal': ticket.status === 'selesai',
                                                  'bg-brand-500/20 text-brand-200': ticket.status === 'berjalan',
                                                  'bg-state-waspada/20 text-state-waspada': ticket.status === 'tertunda',
                                                  'bg-white/10 text-mist-300': ticket.status === 'terjadwal',
                                              }" x-text="ticket.status"></span>
                                    </span>
                                </button>
                            </li>
                        </template>

                        <li x-show="visible.length === 0"
                            class="rounded-xl border border-dashed border-white/12 px-3 py-6 text-center text-[11.5px] text-mist-400">
                            Belum ada tiket pada filter ini.
                        </li>
                    </ul>
                </div>

                <div class="glass glass--panel flex min-h-0 min-w-0 flex-col overflow-hidden p-0 max-lg:min-h-[460px]" x-sheen>
                    <template x-if="active">
                        <div class="flex min-h-0 flex-1 flex-col">
                            <div class="shrink-0 border-b border-white/10 px-4 py-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <h2 class="truncate text-[14px] font-semibold text-white" x-text="active.title"></h2>
                                        <p class="text-[11.5px] text-mist-300">
                                            <span x-text="active.station ?? 'Umum'"></span>
                                            <span class="text-mist-500"> · </span>
                                            <span x-text="active.type_label"></span>
                                            <span class="text-mist-500"> · </span>
                                            <span x-text="'dijadwalkan ' + active.scheduled_for"></span>
                                        </p>
                                    </div>
                                    <span class="rounded-lg px-2 py-1 text-[10.5px] font-semibold uppercase"
                                          :class="{
                                              'bg-state-normal/20 text-state-normal': active.status === 'selesai',
                                              'bg-brand-500/20 text-brand-200': active.status === 'berjalan',
                                              'bg-state-waspada/20 text-state-waspada': active.status === 'tertunda',
                                              'bg-white/10 text-mist-300': active.status === 'terjadwal',
                                          }" x-text="active.status"></span>
                                </div>
                            </div>

                            {{-- The thread: a day heading when the date turns, one
                                 avatar and name per burst, and the clock under the
                                 last line of that burst. Your own lines sit on your
                                 margin; the tint says which side answered. --}}
                            <div class="scroll-y min-h-0 flex-1 space-y-1.5 px-4 py-3.5" x-ref="thread">
                                <template x-for="row in thread" :key="row.id">
                                    <div>
                                        <template x-if="row.type === 'day'">
                                            <p class="my-2.5 text-center">
                                                <span class="rounded-full bg-white/8 px-2.5 py-1 text-[10.5px] font-medium text-mist-300"
                                                      x-text="row.label"></span>
                                            </p>
                                        </template>

                                        <template x-if="row.type === 'unread'">
                                            <p class="my-2.5 flex items-center gap-2">
                                                <span class="h-px flex-1 bg-state-bahaya/40"></span>
                                                <span class="text-[10px] font-semibold tracking-wide text-state-bahaya uppercase">
                                                    Belum dibaca
                                                </span>
                                                <span class="h-px flex-1 bg-state-bahaya/40"></span>
                                            </p>
                                        </template>

                                        <template x-if="row.type === 'message'">
                                            <div class="flex items-end gap-2"
                                                 :class="mine(row.message) ? 'justify-end' : 'justify-start'">

                                                {{-- The avatar column stays reserved inside a
                                                     burst so the bubbles keep one margin. --}}
                                                <span x-show="! mine(row.message)"
                                                      class="grid size-7 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                                                      :class="row.grouped
                                                          ? 'bg-transparent text-transparent'
                                                          : (row.message.role === 'operator'
                                                              ? 'bg-brand-500/25 text-brand-100'
                                                              : 'bg-white/12 text-mist-100')"
                                                      :title="row.message.author"
                                                      x-text="row.grouped ? '' : initials(row.message.author)"></span>

                                                <div class="max-w-[76%] px-3.5 py-2.5"
                                                     :class="[
                                                         mine(row.message)
                                                             ? 'bg-brand-500/22 ring-1 ring-brand-400/30'
                                                             : (row.message.role === 'operator' ? 'bg-white/8 ring-1 ring-brand-400/15' : 'bg-white/8'),
                                                         row.grouped
                                                             ? (mine(row.message) ? 'rounded-2xl rounded-tr-md' : 'rounded-2xl rounded-tl-md')
                                                             : 'rounded-2xl',
                                                     ]">
                                                    <p x-show="! row.grouped"
                                                       class="mb-1 flex items-center gap-1.5 text-[10.5px] font-semibold"
                                                       :class="mine(row.message) ? 'text-brand-200' : 'text-mist-300'">
                                                        <span x-text="mine(row.message) ? 'Anda' : row.message.author"></span>
                                                        <span class="rounded px-1 py-px text-[9px] tracking-wide uppercase"
                                                              :class="mine(row.message) ? 'bg-brand-500/25' : 'bg-white/10'"
                                                              x-text="sideLabel(row.message.role)"></span>
                                                    </p>

                                                    <p class="text-[12.5px] leading-relaxed whitespace-pre-line text-mist-100"
                                                       x-text="row.message.body"></p>

                                                    <p x-show="row.last"
                                                       class="mt-1 flex items-center gap-1.5 text-[10px] text-mist-400">
                                                        <span x-text="row.message.clock ?? row.message.at"></span>
                                                        {{-- A receipt on the last thing you said,
                                                             not on every line. --}}
                                                        <template x-if="mine(row.message) && row.message.id === lastMine(active)">
                                                            <span class="flex items-center gap-1"
                                                                  :class="row.message.read && 'text-brand-200'">
                                                                <x-icon name="check" class="size-3"/>
                                                                <span x-text="row.message.read ? 'Dibaca' : 'Terkirim'"></span>
                                                            </span>
                                                        </template>
                                                    </p>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <p x-show="active.messages.length === 0"
                                   class="py-8 text-center text-[11.5px] text-mist-400">
                                    Belum ada percakapan. Tulis pesan pertama di bawah.
                                </p>
                            </div>

                            @can('maintenance.reply')
                            <div class="shrink-0 border-t border-white/10 p-3">
                                <div class="flex items-end gap-2">
                                    <textarea x-model="draft" rows="2" maxlength="2000"
                                              :placeholder="`Tulis pesan untuk ${desk.other_label.toLowerCase()}…`"
                                              aria-label="Tulis pesan"
                                              class="glass glass--inset min-h-11 w-full px-3 py-2.5 text-[13px] text-white placeholder:text-mist-400"
                                              @keydown.enter.exact.prevent="send()"></textarea>
                                    <button type="button"
                                            class="glass glass--chip glass-button size-11 shrink-0"
                                            title="Kirim pesan" aria-label="Kirim pesan"
                                            :disabled="sending || !draft.trim()"
                                            @click="send()">
                                        <x-icon name="send" class="size-[18px]"/>
                                    </button>
                                </div>
                                <p class="mt-1.5 text-[10.5px] text-mist-400">
                                    Masuk sebagai <span x-text="desk.name"></span> ·
                                    <span x-text="desk.role_label"></span>
                                    <span x-show="desk.role_label !== desk.side_label">(<span
                                        x-text="desk.side_label.toLowerCase()"></span>)</span>
                                    · Enter untuk mengirim, Shift+Enter untuk baris baru
                                </p>
                                <p class="mt-1 text-[11.5px] text-state-bahaya" x-show="error" x-text="error"></p>
                            </div>
                            @else
                            <p class="shrink-0 border-t border-white/10 px-4 py-3 text-[11.5px] text-mist-400">
                                Peran <span class="text-mist-200">{{ auth()->user()?->roleLabel() }}</span>
                                hanya dapat membaca percakapan ini.
                            </p>
                            @endcan
                        </div>
                    </template>

                    <p x-show="!active" class="grid h-full place-items-center px-6 py-16 text-center text-[12px] text-mist-400">
                        Pilih tiket di sebelah kiri untuk membuka percakapannya.
                    </p>
                </div>
            </div>

            {{-- Board ------------------------------------------------------ --}}
            <div x-show="tab === 'papan'" id="desk-panel-papan" role="tabpanel" aria-labelledby="desk-tab-papan" x-cloak
                 class="grid grid-cols-4 gap-3.5 max-xl:grid-cols-2 max-sm:grid-cols-1"
                 x-data="{
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

                        <ul class="scroll-y max-h-[calc(100vh-330px)] space-y-2.5 pr-1">
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

                                    <p class="mt-1.5 text-[11px] text-mist-300">{{ $task->station?->name ?? 'Umum' }}</p>

                                    <div class="mt-2 flex items-center gap-2 text-[10.5px] text-mist-400">
                                        <x-icon name="calendar" class="size-3.5"/>
                                        <span class="tnum">{{ $task->scheduled_for->translatedFormat('d M Y') }}</span>
                                        <span>·</span>
                                        <span>{{ ucfirst($task->type) }}</span>
                                    </div>

                                    <p class="mt-1 flex items-center gap-1.5 text-[10.5px] text-mist-400">
                                        <x-icon name="user" class="size-3.5"/>
                                        {{ $task->assignee ?? 'Belum ditugaskan' }}
                                    </p>

                                    @if ($task->notes)
                                        <p class="mt-2 rounded-lg bg-white/6 px-2 py-1.5 text-[10.5px] text-mist-300">
                                            {{ $task->notes }}
                                        </p>
                                    @endif

                                    <div class="mt-2.5 flex flex-wrap gap-1.5">
                                        @can('maintenance.status')
                                        @foreach (array_diff(array_keys($columns), [$key]) as $target)
                                            <button type="button"
                                                    class="rounded-lg bg-white/8 px-2 py-1 text-[10.5px] font-medium text-mist-200 transition hover:bg-white/16 hover:text-white"
                                                    @click="move({{ $task->id }}, '{{ $target }}')">
                                                → {{ $columns[$target] }}
                                            </button>
                                        @endforeach
                                        @endcan
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

            {{-- History ---------------------------------------------------- --}}
            <div x-show="tab === 'riwayat'" id="desk-panel-riwayat" role="tabpanel" aria-labelledby="desk-tab-riwayat" x-cloak class="glass glass--panel overflow-hidden p-0" x-sheen>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-white/10 px-4 py-3">
                    <div>
                        <h2 class="text-[14px] font-semibold text-white">Riwayat perawatan alat</h2>
                        <p class="text-[11.5px] text-mist-300">Pekerjaan yang sudah selesai, terbaru di atas.</p>
                    </div>
                    <label class="flex items-center gap-2">
                        <span class="text-[11px] text-mist-300">Alat</span>
                        <select x-model="stationFilter"
                                class="glass glass--inset px-3 py-2 text-[12px] text-white">
                            <option value="">Semua alat</option>
                            @foreach ($desk['stations'] as $station)
                                <option value="{{ $station['code'] }}">{{ $station['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="table-scroll">
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="border-b border-white/10 bg-white/4 text-[10.5px] tracking-wide text-mist-300 uppercase">
                                <th class="px-4 py-3 font-semibold">Selesai</th>
                                <th class="px-4 py-3 font-semibold">Alat</th>
                                <th class="px-4 py-3 font-semibold">Pekerjaan</th>
                                <th class="px-4 py-3 font-semibold">Jenis</th>
                                <th class="px-4 py-3 font-semibold">Pelaksana</th>
                                <th class="px-4 py-3 font-semibold">Catatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            <template x-for="row in historyRows" :key="row.id">
                                <tr class="transition hover:bg-white/5">
                                    <td class="tnum px-4 py-3 text-[12px] whitespace-nowrap text-mist-200" x-text="row.completed_at ?? row.scheduled_for"></td>
                                    <td class="px-4 py-3 text-[12.5px] text-white" x-text="row.station ?? 'Umum'"></td>
                                    <td class="px-4 py-3 text-[12.5px] text-mist-100" x-text="row.title"></td>
                                    <td class="px-4 py-3 text-[12px] text-mist-300" x-text="row.type_label"></td>
                                    <td class="px-4 py-3 text-[12px] text-mist-300" x-text="row.assignee ?? '—'"></td>
                                    <td class="px-4 py-3 text-[11.5px] text-mist-400" x-text="row.notes ?? '—'"></td>
                                </tr>
                            </template>

                            <tr x-show="historyRows.length === 0">
                                <td colspan="6" class="px-4 py-10 text-center">
                                    <p class="text-[13px] font-semibold text-white">Belum ada riwayat</p>
                                    <p class="mt-1 text-[11.5px] text-mist-300">
                                        Pekerjaan yang ditandai selesai akan tercatat di sini.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-page-shell>
@endsection

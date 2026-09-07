@extends('layouts.app')

@section('title', 'Pengguna & Akses')

@php
    // Rows the modals prefill from, and — when a save came back with errors —
    // the modal to reopen with what was typed.
    $userRows = $users->map(fn ($user) => [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role,
        'unit' => $user->unit,
        'is_active' => (bool) $user->is_active,
        'self' => $user->is(auth()->user()),
    ])->values();

    $roleRows = $roles->map(fn ($role) => [
        'id' => $role->id,
        'slug' => $role->slug,
        'name' => $role->name,
        'description' => $role->description,
        'desk_side' => $role->desk_side,
        'permissions' => $role->permissions ?? [],
        'is_system' => (bool) $role->is_system,
        'users_count' => $role->users_count,
    ])->values();

    $intent = old('intent');
    $reopen = null;

    if ($intent && ($errors->user->any() || $errors->role->any())) {
        $reopen = [
            'modal' => str_starts_with($intent, 'user') ? 'user' : 'role',
            'tab' => str_starts_with($intent, 'user') ? 'pengguna' : 'peran',
            'target' => old('target_id') ? [
                'id' => (int) old('target_id'),
                'slug' => old('target_slug'),
                'name' => old('name'),
                'email' => old('email'),
                'role' => old('role'),
                'unit' => old('unit'),
                'desk_side' => old('desk_side'),
                'description' => old('description'),
                'is_active' => (bool) old('is_active'),
                'permissions' => old('permissions', []),
            ] : null,
            'draft' => [
                'name' => old('name'),
                'email' => old('email'),
                'role' => old('role'),
                'unit' => old('unit'),
                'desk_side' => old('desk_side'),
                'description' => old('description'),
                'is_active' => (bool) old('is_active'),
                'permissions' => old('permissions', []),
            ],
        ];
    }
@endphp

@section('stage')
    {{-- Accounts and what they may do. The role a person holds decides both the
         menus they can open and the side of the maintenance desk they write
         from, so the two lists live on one screen. --}}
    <x-page-shell wide title="Pengguna & Hak Akses"
                  subtitle="Akun yang dapat masuk, peran yang mereka pegang, dan hak akses tiap peran.">

        <div x-data="accessDesk(@js(['users' => $userRows, 'roles' => $roleRows, 'reopen' => $reopen]))"
             @keydown.escape.window="close()">

            @if (session('status'))
                <div class="glass glass--panel mb-3.5 flex items-center gap-2.5 border-l-2 border-state-normal px-4 py-3"
                     role="status">
                    <x-icon name="check" class="size-4 shrink-0 text-state-normal"/>
                    <p class="text-[12.5px] text-mist-100">{{ session('status') }}</p>
                </div>
            @endif

            @foreach (['user' => 'Data pengguna belum benar', 'role' => 'Data peran belum benar'] as $bag => $heading)
                @if ($errors->{$bag}->any())
                    <div class="glass glass--panel mb-3.5 border-l-2 border-state-bahaya px-4 py-3">
                        <p class="mb-1 flex items-center gap-2 text-[12.5px] font-semibold text-state-bahaya">
                            <x-icon name="warning" class="size-4"/>
                            {{ $heading }}
                        </p>
                        <ul class="ml-6 list-disc text-[11.5px] text-mist-200">
                            @foreach ($errors->{$bag}->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach

            {{-- Tabs --}}
            <div class="mb-3.5 flex flex-wrap items-center justify-between gap-2.5">
                <div class="glass glass--chip flex items-center gap-1 p-1.5" role="tablist" aria-label="Bagian akses">
                    @foreach ([
                        'pengguna' => ['label' => 'Pengguna', 'short' => 'Pengguna', 'icon' => 'users', 'count' => $users->count()],
                        'peran' => ['label' => 'Peran & Hak Akses', 'short' => 'Peran', 'icon' => 'key', 'count' => $roles->count()],
                    ] as $key => $meta)
                        <button type="button" role="tab"
                                id="access-tab-{{ $key }}" aria-controls="access-panel-{{ $key }}"
                                class="flex items-center gap-2 rounded-xl px-4 py-2 text-[12.5px] font-semibold transition max-sm:px-3"
                                :aria-selected="tab === '{{ $key }}'"
                                :class="tab === '{{ $key }}'
                                    ? 'bg-brand-500/90 text-white shadow-[0_10px_24px_-12px_rgba(31,139,245,.9)]'
                                    : 'text-mist-200 hover:bg-white/8'"
                                @click="tab = '{{ $key }}'">
                            <x-icon :name="$meta['icon']" class="size-4 shrink-0"/>
                            {{-- The long label wraps to two lines on a phone. --}}
                            <span class="max-sm:hidden">{{ $meta['label'] }}</span>
                            <span class="sm:hidden">{{ $meta['short'] }}</span>
                            <span class="tnum rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] font-bold">{{ $meta['count'] }}</span>
                        </button>
                    @endforeach
                </div>

                <button type="button" class="glass glass--chip glass-button gap-2 px-4 py-2.5 text-[12.5px] font-semibold"
                        x-show="tab === 'pengguna'"
                        @click="openUser()">
                    <x-icon name="plus" class="size-4"/>
                    Tambah pengguna
                </button>

                @can('roles.manage')
                    <button type="button" class="glass glass--chip glass-button gap-2 px-4 py-2.5 text-[12.5px] font-semibold"
                            x-show="tab === 'peran'" x-cloak
                            @click="openRole()">
                        <x-icon name="plus" class="size-4"/>
                        Peran baru
                    </button>
                @endcan
            </div>

            {{-- Accounts ---------------------------------------------------- --}}
            <div x-show="tab === 'pengguna'" id="access-panel-pengguna" role="tabpanel" aria-labelledby="access-tab-pengguna">
                {{-- A five-column table on a phone is a scroll bar with rows in
                     it, so below sm the same accounts render as cards. --}}
                <div class="glass glass--panel overflow-hidden p-0 max-sm:hidden" x-sheen>
                    <div class="table-scroll">
                        {{-- Fixed columns: an auto table spreads four short cells
                             across a 1600px screen and the row falls apart. --}}
                        <table class="w-full min-w-[760px] table-fixed border-collapse text-left">
                            <colgroup>
                                <col style="width: 34%">
                                <col style="width: 20%">
                                <col style="width: 18%">
                                <col style="width: 14%">
                                <col style="width: 14%">
                            </colgroup>
                            <thead>
                                <tr class="border-b border-white/10 bg-white/4 text-[10.5px] tracking-wide text-mist-300 uppercase">
                                    <th class="px-4 py-3 font-semibold">Nama</th>
                                    <th class="px-4 py-3 font-semibold">Peran</th>
                                    <th class="px-4 py-3 font-semibold">Sisi perawatan</th>
                                    <th class="px-4 py-3 font-semibold">Status</th>
                                    <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($users as $index => $user)
                                    @php($role = $user->accessRole())
                                    <tr class="align-top transition hover:bg-white/4">
                                        <td class="px-4 py-3">
                                            <p @class([
                                                'flex items-center gap-1.5 text-[12.5px] font-semibold',
                                                'text-white' => $user->is_active,
                                                'text-mist-300' => ! $user->is_active,
                                            ])>
                                                <span class="truncate">{{ $user->name }}</span>
                                                @if ($user->is(auth()->user()))
                                                    <span class="shrink-0 rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-medium text-mist-300">Anda</span>
                                                @endif
                                            </p>
                                            <p class="truncate text-[11px] text-mist-400" title="{{ $user->email }}">{{ $user->email }}</p>
                                            @if ($user->unit)
                                                <p class="truncate text-[10.5px] text-mist-500" title="{{ $user->unit }}">{{ $user->unit }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-block max-w-full truncate rounded-lg bg-brand-500/18 px-2 py-1 align-middle text-[11px] font-semibold text-brand-200">
                                                {{ $role?->name ?? $user->role }}
                                            </span>
                                            @if (! $role)
                                                <p class="mt-1 text-[10.5px] text-state-waspada">Peran sudah tidak ada</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-[12px] text-mist-200">{{ $user->sideLabel() }}</td>
                                        <td class="px-4 py-3">
                                            {{-- A pill, not a word: the difference between an account that
                                                 can sign in and one that cannot has to survive a glance. --}}
                                            <span @class([
                                                'inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-semibold ring-1',
                                                'bg-state-normal/14 text-state-normal ring-state-normal/30' => $user->is_active,
                                                'bg-white/6 text-mist-400 ring-white/12' => ! $user->is_active,
                                            ])>
                                                <span class="status-dot shrink-0 @class(['bg-state-normal' => $user->is_active, 'bg-mist-500' => ! $user->is_active])"></span>
                                                {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            {{-- The delete slot is held open even when the row has no
                                                 delete, so every Ubah lands on the same column. --}}
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button type="button"
                                                        class="rounded-lg bg-white/8 px-2.5 py-1.5 text-[11px] font-medium text-mist-100 transition hover:bg-white/16"
                                                        @click="openUser(users[{{ $index }}])">
                                                    Ubah
                                                </button>

                                                @if ($user->is(auth()->user()))
                                                    <span class="size-[26px] shrink-0" aria-hidden="true"></span>
                                                @else
                                                    <button type="button"
                                                            class="grid size-[26px] shrink-0 place-items-center rounded-lg bg-state-bahaya/14 text-state-bahaya transition hover:bg-state-bahaya/24"
                                                            title="Hapus pengguna" aria-label="Hapus {{ $user->name }}"
                                                            @click="askDelete('user', users[{{ $index }}])">
                                                        <x-icon name="trash" class="size-3.5"/>
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <ul class="space-y-2.5 sm:hidden">
                    @foreach ($users as $index => $user)
                        @php($role = $user->accessRole())
                        <li class="glass glass--panel p-3.5" x-sheen>
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p @class([
                                        'flex items-center gap-1.5 text-[13.5px] font-semibold',
                                        'text-white' => $user->is_active,
                                        'text-mist-300' => ! $user->is_active,
                                    ])>
                                        <span class="truncate">{{ $user->name }}</span>
                                        @if ($user->is(auth()->user()))
                                            <span class="shrink-0 rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-medium text-mist-300">Anda</span>
                                        @endif
                                    </p>
                                    <p class="truncate text-[11px] text-mist-400">{{ $user->email }}</p>
                                </div>

                                <span @class([
                                    'inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-semibold ring-1',
                                    'bg-state-normal/14 text-state-normal ring-state-normal/30' => $user->is_active,
                                    'bg-white/6 text-mist-400 ring-white/12' => ! $user->is_active,
                                ])>
                                    <span class="status-dot shrink-0 @class(['bg-state-normal' => $user->is_active, 'bg-mist-500' => ! $user->is_active])"></span>
                                    {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </div>

                            <p class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] text-mist-300">
                                <span class="rounded-lg bg-brand-500/18 px-2 py-1 font-semibold text-brand-200">
                                    {{ $role?->name ?? $user->role }}
                                </span>
                                <span class="text-mist-400">{{ $user->sideLabel() }}</span>
                            </p>

                            @if ($user->unit)
                                <p class="mt-1.5 text-[11px] text-mist-500">{{ $user->unit }}</p>
                            @endif

                            <div class="mt-2.5 flex items-center gap-2">
                                <button type="button"
                                        class="min-h-10 flex-1 rounded-xl bg-white/8 px-3 text-[12px] font-medium text-mist-100 transition hover:bg-white/16"
                                        @click="openUser(users[{{ $index }}])">
                                    Ubah
                                </button>

                                @unless ($user->is(auth()->user()))
                                    <button type="button"
                                            class="grid min-h-10 w-12 place-items-center rounded-xl bg-state-bahaya/14 text-state-bahaya transition hover:bg-state-bahaya/24"
                                            title="Hapus pengguna" aria-label="Hapus {{ $user->name }}"
                                            @click="askDelete('user', users[{{ $index }}])">
                                        <x-icon name="trash" class="size-4"/>
                                    </button>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Roles ------------------------------------------------------- --}}
            {{-- A list, not a wall of checkboxes: what a role may do belongs in
                 its dialog, so the table stays scannable when there are ten of
                 them. --}}
            <div x-show="tab === 'peran'" x-cloak id="access-panel-peran" role="tabpanel" aria-labelledby="access-tab-peran">
                <div class="glass glass--panel overflow-hidden p-0 max-sm:hidden" x-sheen>
                    <div class="table-scroll">
                        <table class="w-full min-w-[760px] table-fixed border-collapse text-left">
                            <colgroup>
                                <col style="width: 26%">
                                <col style="width: 34%">
                                <col style="width: 18%">
                                <col style="width: 8%">
                                <col style="width: 14%">
                            </colgroup>
                            <thead>
                                <tr class="border-b border-white/10 bg-white/4 text-[10.5px] tracking-wide text-mist-300 uppercase">
                                    <th class="px-4 py-3 font-semibold">Peran</th>
                                    <th class="px-4 py-3 font-semibold">Keterangan</th>
                                    <th class="px-4 py-3 font-semibold">Sisi perawatan</th>
                                    <th class="px-4 py-3 text-right font-semibold">Pengguna</th>
                                    <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($roles as $index => $role)
                                    <tr class="align-top transition hover:bg-white/4">
                                        <td class="px-4 py-3">
                                            <p class="truncate text-[12.5px] font-semibold text-white">{{ $role->name }}</p>
                                            <p class="truncate text-[10.5px] text-mist-400">
                                                <span class="font-mono">{{ $role->slug }}</span>
                                                @if ($role->is_system)
                                                    <span class="px-1">·</span>
                                                    <span>bawaan sistem</span>
                                                @endif
                                            </p>
                                        </td>
                                        <td class="px-4 py-3 text-[12px] leading-relaxed text-mist-300">
                                            {{ $role->description ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-block max-w-full truncate rounded-lg bg-white/10 px-2 py-1 text-[11px] font-semibold text-mist-100">
                                                {{ $role->sideLabel() }}
                                            </span>
                                        </td>
                                        <td class="tnum px-4 py-3 text-right text-[12px] text-mist-200">{{ $role->users_count }}</td>
                                        <td class="px-4 py-3">
                                            @can('roles.manage')
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <button type="button"
                                                            class="rounded-lg bg-white/8 px-2.5 py-1.5 text-[11px] font-medium text-mist-100 transition hover:bg-white/16"
                                                            @click="openRole(roles[{{ $index }}])">
                                                        Ubah
                                                    </button>

                                                    @if ($role->is_system)
                                                        <span class="size-[26px] shrink-0" aria-hidden="true"></span>
                                                    @else
                                                        <button type="button"
                                                                class="grid size-[26px] shrink-0 place-items-center rounded-lg bg-state-bahaya/14 text-state-bahaya transition hover:bg-state-bahaya/24"
                                                                title="Hapus peran" aria-label="Hapus peran {{ $role->name }}"
                                                                @click="askDelete('role', roles[{{ $index }}])">
                                                            <x-icon name="trash" class="size-3.5"/>
                                                        </button>
                                                    @endif
                                                </div>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <ul class="space-y-2.5 sm:hidden">
                    @foreach ($roles as $index => $role)
                        <li class="glass glass--panel p-3.5" x-sheen>
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-[13.5px] font-semibold text-white">{{ $role->name }}</p>
                                    <p class="truncate text-[11px] text-mist-400">
                                        <span class="font-mono">{{ $role->slug }}</span>
                                        @if ($role->is_system)
                                            <span class="px-1">·</span>
                                            <span>bawaan sistem</span>
                                        @endif
                                    </p>
                                </div>

                                <span class="shrink-0 rounded-lg bg-white/10 px-2 py-1 text-[11px] font-semibold text-mist-100">
                                    {{ $role->sideLabel() }}
                                </span>
                            </div>

                            @if ($role->description)
                                <p class="mt-2 text-[11.5px] leading-relaxed text-mist-300">{{ $role->description }}</p>
                            @endif

                            <p class="mt-1.5 text-[11px] text-mist-400">{{ $role->users_count }} pengguna</p>

                            @can('roles.manage')
                                <div class="mt-2.5 flex items-center gap-2">
                                    <button type="button"
                                            class="min-h-10 flex-1 rounded-xl bg-white/8 px-3 text-[12px] font-medium text-mist-100 transition hover:bg-white/16"
                                            @click="openRole(roles[{{ $index }}])">
                                        Ubah
                                    </button>

                                    @unless ($role->is_system)
                                        <button type="button"
                                                class="grid min-h-10 w-12 place-items-center rounded-xl bg-state-bahaya/14 text-state-bahaya transition hover:bg-state-bahaya/24"
                                                title="Hapus peran" aria-label="Hapus peran {{ $role->name }}"
                                                @click="askDelete('role', roles[{{ $index }}])">
                                            <x-icon name="trash" class="size-4"/>
                                        </button>
                                    @endunless
                                </div>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Modals ------------------------------------------------------ --}}

            {{-- Account: one form for both adding and editing. --}}
            <template x-teleport="body">
            <div x-show="modal === 'user'" x-cloak x-transition.opacity.duration.150ms class="modal-scrim" @click.self="close()">
                <div class="modal-card glass glass--panel glass--menu" x-show="modal === 'user'" x-transition
                     role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
                    <form method="POST" :action="target ? '/pengguna/' + target.id : '{{ route('users.store') }}'">
                        @csrf
                        <template x-if="target">
                            <div>
                                <input type="hidden" name="_method" value="PUT">
                                <input type="hidden" name="target_id" :value="target.id">
                            </div>
                        </template>
                        <input type="hidden" name="intent" :value="target ? 'user-edit' : 'user-new'">

                        <div class="flex items-start justify-between gap-3 border-b border-white/10 px-4 py-3">
                            <div>
                                <h2 id="user-modal-title" class="text-[14px] font-semibold text-white"
                                    x-text="target ? 'Ubah pengguna' : 'Pengguna baru'"></h2>
                                <p class="mt-0.5 text-[11.5px] text-mist-300">
                                    Perannya menentukan menu yang terbuka dan sisi percakapan pada menu Perawatan.
                                </p>
                            </div>
                            <button type="button" class="glass glass--chip glass-button size-9 shrink-0"
                                    title="Tutup" aria-label="Tutup" @click="close()">
                                <x-icon name="x" class="size-4"/>
                            </button>
                        </div>

                        <div class="scroll-y max-h-[62vh] px-4 py-4">
                            <div class="grid gap-2.5 md:grid-cols-2">
                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Nama</span>
                                    <input type="text" name="name" required maxlength="80"
                                           :value="draft.name ?? ''"
                                           class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white placeholder:text-mist-400"
                                           placeholder="Mis. Rizal Pratama">
                                </label>

                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Email</span>
                                    <input type="email" name="email" required maxlength="120"
                                           :value="draft.email ?? ''"
                                           class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white placeholder:text-mist-400"
                                           placeholder="nama@bwssulawesi5.go.id">
                                </label>

                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Peran</span>
                                    <select name="role" x-model="draft.role"
                                            class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white">
                                        @foreach ($roles as $option)
                                            <option value="{{ $option->slug }}">{{ $option->name }} — {{ $option->sideLabel() }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300">Unit / satuan kerja</span>
                                    <input type="text" name="unit" maxlength="120" :value="draft.unit ?? ''"
                                           class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white placeholder:text-mist-400"
                                           placeholder="Petugas OP Bendungan Budong Budong">
                                </label>

                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-medium text-mist-300"
                                          x-text="target ? 'Kata sandi baru' : 'Kata sandi awal'"></span>
                                    <input type="password" name="password" minlength="8" :required="! target"
                                           autocomplete="new-password"
                                           class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white"
                                           :placeholder="target ? 'Kosongkan bila tidak diubah' : 'Minimal 8 karakter'">
                                </label>

                                <label class="flex items-center gap-2.5 self-end pb-2.5">
                                    <input type="checkbox" name="is_active" value="1" x-model="draft.is_active"
                                           class="size-4 rounded border-white/20 bg-white/10 accent-brand-500">
                                    <span class="text-[12px] text-mist-200">Akun aktif</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-white/10 px-4 py-3">
                            <button type="button" class="text-[12px] text-mist-300 transition hover:text-white"
                                    @click="close()">Batal</button>
                            <button type="submit" class="glass glass--chip glass-button px-4 py-2.5 text-[12.5px] font-semibold">
                                <x-icon name="save" class="size-4"/>
                                <span x-text="target ? 'Simpan perubahan' : 'Simpan pengguna'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            </template>

            {{-- Role: name, side, and the abilities it carries. --}}
            @can('roles.manage')
                <template x-teleport="body">
                <div x-show="modal === 'role'" x-cloak x-transition.opacity.duration.150ms class="modal-scrim" @click.self="close()">
                    <div class="modal-card modal-card--wide glass glass--panel glass--menu" x-show="modal === 'role'" x-transition
                         role="dialog" aria-modal="true" aria-labelledby="role-modal-title">
                        <form method="POST" :action="target ? '/peran/' + target.slug : '{{ route('roles.store') }}'">
                            @csrf
                            <template x-if="target">
                                <div>
                                    <input type="hidden" name="_method" value="PUT">
                                    <input type="hidden" name="target_id" :value="target.id">
                                    <input type="hidden" name="target_slug" :value="target.slug">
                                </div>
                            </template>
                            <input type="hidden" name="intent" :value="target ? 'role-edit' : 'role-new'">

                            <div class="flex items-start justify-between gap-3 border-b border-white/10 px-4 py-3">
                                <div>
                                    <h2 id="role-modal-title" class="text-[14px] font-semibold text-white"
                                        x-text="target ? 'Ubah peran ' + target.name : 'Peran baru'"></h2>
                                    <p class="mt-0.5 text-[11.5px] text-mist-300"
                                       x-text="isAdminRole
                                           ? 'Nama, keterangan, dan sisi perawatan peran ini.'
                                           : 'Pilih sisi perawatan dan centang hak akses yang dibawa peran ini.'"></p>
                                </div>
                                <button type="button" class="glass glass--chip glass-button size-9 shrink-0"
                                        title="Tutup" aria-label="Tutup" @click="close()">
                                    <x-icon name="x" class="size-4"/>
                                </button>
                            </div>

                            <div class="scroll-y max-h-[62vh] px-4 py-4">
                                <div class="grid gap-2.5 md:grid-cols-3">
                                    <label class="block">
                                        <span class="mb-1 block text-[11px] font-medium text-mist-300">Nama peran</span>
                                        <input type="text" name="name" required maxlength="60"
                                               :value="draft.name ?? ''"
                                               class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white placeholder:text-mist-400"
                                               placeholder="Mis. Teknisi Lapangan">
                                    </label>

                                    <label class="block md:col-span-2">
                                        <span class="mb-1 block text-[11px] font-medium text-mist-300">Keterangan</span>
                                        <input type="text" name="description" maxlength="180"
                                               :value="draft.description ?? ''"
                                               class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white placeholder:text-mist-400"
                                               placeholder="Singkat saja — tugas utama peran ini.">
                                    </label>

                                    <label class="block">
                                        <span class="mb-1 block text-[11px] font-medium text-mist-300">Sisi perawatan</span>
                                        <select name="desk_side" x-model="draft.desk_side"
                                                class="glass glass--inset w-full px-3 py-2.5 text-[13px] text-white">
                                            @foreach ($sides as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>

                                <p x-show="isAdminRole" x-cloak
                                   class="mt-3 flex items-center gap-2 rounded-xl bg-brand-500/12 px-3 py-2 text-[11.5px] text-brand-200">
                                    <x-icon name="shield-check" class="size-4 shrink-0"/>
                                    Peran administrator selalu memegang seluruh hak akses; centangnya tidak dapat diubah.
                                </p>

                                <div class="mt-3 grid gap-3 md:grid-cols-2" x-show="! isAdminRole">
                                    @foreach ($catalogue as $group => $abilities)
                                        <fieldset class="glass glass--inset p-3">
                                            <legend class="px-1 text-[11px] font-semibold tracking-wide text-mist-300 uppercase">{{ $group }}</legend>
                                            <div class="mt-1.5 space-y-1.5">
                                                @foreach ($abilities as $code => $meta)
                                                    <label class="flex items-start gap-2.5">
                                                        <input type="checkbox" name="permissions[]" value="{{ $code }}"
                                                               x-model="draft.permissions"
                                                               class="mt-0.5 size-4 shrink-0 rounded border-white/20 bg-white/10 accent-brand-500">
                                                        <span>
                                                            <span class="block text-[12px] text-mist-100">{{ $meta['label'] }}</span>
                                                            <span class="block text-[10.5px] text-mist-400">{{ $meta['hint'] }}</span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-3 border-t border-white/10 px-4 py-3">
                                <button type="button" class="text-[12px] text-mist-300 transition hover:text-white"
                                        @click="close()">Batal</button>
                                <button type="submit" class="glass glass--chip glass-button px-4 py-2.5 text-[12.5px] font-semibold">
                                    <x-icon name="save" class="size-4"/>
                                    <span x-text="target ? 'Simpan perubahan' : 'Buat peran'"></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                </template>
            @endcan

            {{-- Deleting asks in the page, not in a browser dialog. --}}
            <template x-teleport="body">
            <div x-show="modal === 'delete'" x-cloak x-transition.opacity.duration.150ms class="modal-scrim" @click.self="close()">
                <div class="modal-card modal-card--narrow glass glass--panel glass--menu" x-show="modal === 'delete'" x-transition
                     role="alertdialog" aria-modal="true" aria-labelledby="delete-modal-title">
                    <form method="POST" :action="(deleteKind === 'user' ? '/pengguna/' : '/peran/') + (deleteKind === 'user' ? target?.id : target?.slug)">
                        @csrf
                        @method('DELETE')

                        <div class="px-4 pt-4">
                            <h2 id="delete-modal-title" class="flex items-center gap-2 text-[14px] font-semibold text-white">
                                <x-icon name="warning" class="size-4 text-state-bahaya"/>
                                <span x-text="deleteKind === 'user' ? 'Hapus pengguna' : 'Hapus peran'"></span>
                            </h2>
                            <p class="mt-2 text-[12.5px] leading-relaxed text-mist-200">
                                <span x-text="deleteKind === 'user'
                                    ? 'Akun ini tidak akan bisa masuk lagi. Pesan yang pernah ditulisnya tetap tersimpan.'
                                    : 'Peran ini akan hilang dari daftar. Peran yang masih dipakai tidak dapat dihapus.'"></span>
                            </p>
                            <p class="mt-2.5 rounded-xl bg-white/6 px-3 py-2 text-[12.5px] font-semibold text-white"
                               x-text="target?.name"></p>
                        </div>

                        <div class="mt-4 flex items-center justify-end gap-3 border-t border-white/10 px-4 py-3">
                            <button type="button" class="text-[12px] text-mist-300 transition hover:text-white"
                                    @click="close()">Batal</button>
                            <button type="submit"
                                    class="rounded-xl bg-state-bahaya/18 px-4 py-2.5 text-[12.5px] font-semibold text-state-bahaya transition hover:bg-state-bahaya/28">
                                Hapus
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            </template>
        </div>
    </x-page-shell>
@endsection

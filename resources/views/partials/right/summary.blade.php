{{-- Right panel: site-wide summary. Data comes from the polled `site` store.

     Arranged like the dashboard board, and by the same control: the two are
     one screen to the reader, so ordering the page and leaving this column
     fixed would be half a feature. Stored separately, because this column
     follows the reader onto every other page.

     `skipPrimary` still stands: the dashboard prints those four figures across
     the top already, so it asks for them to be left out rather than repeated. --}}
@php($panel = \App\Support\DashboardLayout::current('panel'))

<div class="scroll-y flex h-full flex-col gap-3.5 pr-0.5">
    @foreach ($panel as $card)
        @continue(($card['key'] === 'primary') && ($skipPrimary ?? false))

        <div class="panel-card{{ $card['hidden'] ? ' panel-card--off' : '' }} shrink-0"
             data-panel-card="{{ $card['key'] }}">

            @can('dashboard.arrange')
                <template x-if="$store.arrange.editing">
                    <div class="dash-card__bar">
                        <span class="truncate text-[11.5px] font-semibold text-white">{{ $card['label'] }}</span>

                        <span class="ml-auto flex items-center gap-1">
                            <button type="button" class="dash-card__act" title="Naikkan"
                                    aria-label="Naikkan {{ $card['label'] }}"
                                    @click="$store.arrange.move('panel', '{{ $card['key'] }}', -1)">
                                <x-icon name="arrow-up" class="size-3.5"/>
                            </button>
                            <button type="button" class="dash-card__act" title="Turunkan"
                                    aria-label="Turunkan {{ $card['label'] }}"
                                    @click="$store.arrange.move('panel', '{{ $card['key'] }}', 1)">
                                <x-icon name="arrow-down" class="size-3.5"/>
                            </button>

                            @if (! empty(\App\Support\DashboardLayout::choices('panel')[$card['key']]))
                                <button type="button" class="dash-card__act px-2 text-[10.5px] font-semibold"
                                        title="Atur isi {{ $card['label'] }}"
                                        @click="$store.arrange.openOptions('panel', '{{ $card['key'] }}')">
                                    Isi
                                </button>
                            @endif

                            <button type="button" class="dash-card__act" title="Sembunyikan"
                                    :aria-pressed="$store.arrange.isHidden('panel', '{{ $card['key'] }}')"
                                    aria-label="Sembunyikan {{ $card['label'] }}"
                                    @click="$store.arrange.toggleHidden('panel', '{{ $card['key'] }}')">
                                <x-icon name="eye" class="size-3.5"/>
                            </button>
                        </span>
                    </div>
                </template>
            @endcan

            @include('partials.right.summary.' . $card['key'], ['card' => $card])
        </div>
    @endforeach
</div>

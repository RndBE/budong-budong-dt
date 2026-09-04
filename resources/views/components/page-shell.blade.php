@props(['title' => null, 'subtitle' => null, 'wide' => false])

{{-- Shared layout for the non-map pages: dimmed live backdrop + scrolling content. --}}
<div class="absolute inset-0">

    {{-- The same time-of-day render as the map, pushed back so text stays readable. --}}
    <div class="absolute inset-0 overflow-hidden">
        <div class="stage-layer scale-105 transition-[filter] duration-1000"
             :style="{
                 backgroundImage: `url('${$store.site.scene.primary}')`,
                 filter: $store.site.stageFilter + ' blur(2px)',
             }"></div>
        <div class="stage-layer scale-105 transition-opacity duration-1000"
             :style="{
                 backgroundImage: `url('${$store.site.scene.secondary}')`,
                 filter: $store.site.stageFilter + ' blur(2px)',
                 opacity: $store.site.scene.mix,
             }"></div>
        <div class="absolute inset-0"
             style="background:
                linear-gradient(180deg, rgba(3,11,20,.82) 0%, rgba(3,11,20,.66) 40%, rgba(3,11,20,.86) 100%)">
        </div>
    </div>

    <div class="chrome-scale scroll-y absolute z-20 pr-1.5 max-sm:!bottom-[var(--stage-bottom)]"
         style="top: var(--stage-top); bottom: var(--gap); left: var(--stage-left);
                right: {{ $wide ? 'var(--gap)' : 'var(--stage-right)' }}">
        @if ($title)
            <div class="mb-3.5 flex flex-wrap items-end justify-between gap-4 max-sm:gap-2">
                <div>
                    <h1 class="text-[22px] leading-tight font-bold text-white">{{ $title }}</h1>
                    @if ($subtitle)
                        <p class="mt-1 text-[12.5px] text-mist-300">{{ $subtitle }}</p>
                    @endif
                </div>
                @isset($actions)
                    <div class="flex items-center gap-2">{{ $actions }}</div>
                @endisset
            </div>
        @endif

        {{ $slot }}
    </div>
</div>

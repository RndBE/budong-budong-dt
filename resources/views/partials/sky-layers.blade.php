{{-- Cloud and rain over whatever picture is behind them: the stage sphere or a
     page's panorama backdrop. Opacity comes from the instruments (or from the
     scenario the reader picked), so one partial serves both. --}}
<div class="sky-veil" aria-hidden="true"
     :style="{ opacity: ($store.site.sky.cloud ?? 0) * 0.72 }"></div>

{{-- The cloud itself, over the grey the veil put in. Two decks at different
     heights and speeds; how bright, how low and how fast all come from the
     same cloud figure, so `berawan` reads as gaps and `mendung` as a lid. --}}
<template x-if="($store.site.sky.cloud ?? 0) > 0.06">
    <div class="sky-clouds" aria-hidden="true"
         :style="{ '--cloud-tile': $store.site.cloudTile + 'px' }">
        <div class="sky-clouds__deck sky-clouds__deck--high" :style="$store.site.cloudDecks.high"></div>
        <div class="sky-clouds__deck" :style="$store.site.cloudDecks.low"></div>
    </div>
</template>

{{-- Aerial perspective: weather takes the distance before it takes the
     foreground, so the grey collects around the horizon instead of lying
     evenly over the frame. Rain does far more of it than cloud alone. --}}
<div class="sky-haze" aria-hidden="true"
     :style="{ opacity: 0.16 * ($store.site.sky.cloud ?? 0) + 0.32 * ($store.site.sky.rain ?? 0) }"></div>

<template x-if="($store.site.sky.rain ?? 0) > 0.02">
    <div aria-hidden="true">
        {{-- Rain is weather over a photograph, not a curtain across it: the
             sheets stay light enough that the dam is still the subject. Their
             spacing, drop length and speed come from the rain figure. --}}
        <div class="sky-rain" :style="$store.site.rainSheets.near"></div>
        <div class="sky-rain sky-rain--far" :style="$store.site.rainSheets.far"></div>
    </div>
</template>

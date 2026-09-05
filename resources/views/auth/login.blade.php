<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · Digital Twin Bendungan Budong Budong</title>
    <link rel="icon" href="{{ asset('assets/icon/favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">

<svg width="0" height="0" class="absolute" aria-hidden="true">
    <filter id="lg-warp" x="-20%" y="-20%" width="140%" height="140%">
        <feTurbulence type="fractalNoise" baseFrequency="0.006 0.012" numOctaves="2" seed="7" result="noise"/>
        <feGaussianBlur in="noise" stdDeviation="3.5" result="soft"/>
        <feDisplacementMap in="SourceGraphic" in2="soft" scale="16" xChannelSelector="R" yChannelSelector="G"/>
    </filter>
</svg>

@php($scene = $environment['scene'])

<div class="relative grid h-screen w-screen place-items-center overflow-hidden">

    {{-- Same time-of-day render as the dashboard --}}
    <div class="absolute inset-0">
        <div class="stage-layer scale-105"
             style="background-image: url('{{ $scene['assets'][$scene['primary']] }}');
                    filter: brightness({{ $scene['grade']['brightness'] }}) contrast({{ $scene['grade']['contrast'] }}) saturate({{ $scene['grade']['saturate'] }});"></div>
        <div class="stage-layer scale-105"
             style="background-image: url('{{ $scene['assets'][$scene['secondary']] }}');
                    opacity: {{ $scene['mix'] }};"></div>
        <div class="absolute inset-0"
             style="background: linear-gradient(180deg, rgba(3,11,20,.72), rgba(3,11,20,.55) 45%, rgba(3,11,20,.88))"></div>
    </div>

    <div class="glass glass--panel viewer-enter relative z-10 w-[400px] p-7" x-data x-sheen>
        <div class="flex items-center gap-3">
            <img src="{{ asset('assets/logopu.png') }}" alt="Logo Kementerian Pekerjaan Umum"
                 class="size-12 shrink-0 rounded-2xl object-cover shadow-[0_16px_34px_-16px_rgba(2,8,20,.9)]">
            <div class="leading-tight">
                <h1 class="text-[16px] font-extrabold tracking-tight text-white uppercase">Bendungan Budong Budong</h1>
                <p class="text-[11px] font-semibold tracking-wide text-mist-200 uppercase">BWS Sulawesi V</p>
            </div>
        </div>

        <p class="mt-5 text-[13px] leading-relaxed text-mist-300">
            Digital Twin &amp; Dam Monitoring System. Masuk dengan akun operator untuk melihat instrumentasi,
            panorama 360°, dan peringatan.
        </p>

        <form method="POST" action="{{ route('login') }}" class="mt-5 space-y-3">
            @csrf

            <label class="block">
                <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Email</span>
                <input type="email" name="email" value="{{ old('email', 'admin@bwssulawesi5.go.id') }}" required autofocus
                       class="glass glass--inset w-full px-3.5 py-2.5 text-[13px] text-white">
            </label>

            <label class="block">
                <span class="mb-1.5 block text-[11px] font-medium text-mist-300">Kata Sandi</span>
                <input type="password" name="password" required
                       class="glass glass--inset w-full px-3.5 py-2.5 text-[13px] text-white">
            </label>

            @error('email')
                <p class="rounded-xl bg-state-bahaya/15 px-3 py-2 text-[11.5px] text-state-bahaya">{{ $message }}</p>
            @enderror

            <label class="flex items-center gap-2 text-[12px] text-mist-200">
                <input type="checkbox" name="remember" value="1" class="accent-brand-500">
                Ingat perangkat ini
            </label>

            <button type="submit"
                    class="mt-1 w-full rounded-2xl bg-linear-to-r from-brand-500 to-brand-600 py-3 text-[13.5px] font-bold text-white transition hover:brightness-110">
                Masuk ke Dashboard
            </button>
        </form>

        <div class="mt-5 border-t border-white/10 pt-3.5 text-[11px] text-mist-400">
            <p class="font-semibold text-mist-300">Akun demo</p>
            <p class="mt-1 font-mono">admin@bwssulawesi5.go.id · password</p>
            <p class="font-mono">operator@bwssulawesi5.go.id · password</p>
        </div>
    </div>

    <p class="absolute bottom-5 z-10 text-[11px] text-mist-400">
        {{ $environment['clock']['date'] }} · {{ $environment['clock']['time'] }} {{ $environment['clock']['zone_label'] }}
        · {{ $environment['weather']['condition_label'] }} {{ $environment['weather']['temperature_c'] }}°C
    </p>
</div>

</body>
</html>

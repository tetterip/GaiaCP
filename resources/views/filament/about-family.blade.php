@php
    use App\Support\About;

    // A white pictogram for each member, drawn on the GaiaCP hexagon (24 × 24, stroked)
    $icons = [
        'chaos' => 'M12 12a1 1 0 1 1 1 1 3 3 0 1 1-3-3 5 5 0 1 1 5 5 7 7 0 1 1-7-7 9 9 0 0 1 9 9',
        'uranus' => 'M12 3l1.8 3.9 4.2.5-3.1 2.9.8 4.2L12 12.4 8.3 14.5l.8-4.2L6 7.4l4.2-.5zM5 18h.01M19 17h.01M12 20h.01M19 4h.01',
        'pontus' => 'M2 8c2-2 4-2 6 0s4 2 6 0 4-2 6 0M2 13c2-2 4-2 6 0s4 2 6 0 4-2 6 0M2 18c2-2 4-2 6 0s4 2 6 0 4-2 6 0',
        'oceanus' => 'M12 3a9 9 0 1 0 0 18 9 9 0 1 0 0-18zM3.5 10c3-2 5.5 2 8.5 0s5.5-2 8.5 0M3.5 15c3-2 5.5 2 8.5 0s5.5-2 8.5 0',
        'hyperion' => 'M12 8a4 4 0 1 0 0 8 4 4 0 1 0 0-8zM12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
        'themis' => 'M12 3v17M8 21h8M4 7h16M6 7l-3 6a3 3 0 0 0 6 0zM18 7l-3 6a3 3 0 0 0 6 0z',
        'mnemosyne' => 'M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3zM5 17a3 3 0 0 1 3-3h11M9 7h6M9 10h4',
        'cronus' => 'M6 3h12M6 21h12M7 3v3c0 3 5 4 5 6s-5 3-5 6v3M17 3v3c0 3-5 4-5 6s5 3 5 6v3',
        'rhea' => 'M4 19h16M5 19V9l3 2.5L10 6l2 4 2-4 2 5.5L19 9v10M9 15h6',
        'hestia' => 'M12 3c3 4 5 6 5 10a5 5 0 0 1-10 0c0-2 1-3.5 2-4.5 0 2 1 3.5 2 3.5 0-3-1-5 1-9zM5 21h14',
        'demeter' => 'M12 21V8M12 8C10 7 9 5 9 3c2 1 3 3 3 5zM12 8c2-1 3-3 3-5-2 1-3 3-3 5zM12 13c-2-1-3-3-3-5 2 1 3 3 3 5zM12 13c2-1 3-3 3-5-2 1-3 3-3 5zM12 18c-2-1-3-3-3-5 2 1 3 3 3 5zM12 18c2-1 3-3 3-5-2 1-3 3-3 5z',
        'hera' => 'M3 8l4 4 5-7 5 7 4-4-2 11H5zM5 21h14',
        'hades' => 'M12 22V9M7 3v4a5 5 0 0 0 10 0V3M6 5l1-2 1 2M16 5l1-2 1 2M10 18h4',
        'poseidon' => 'M12 22V8M5 3v3a7 7 0 0 0 14 0V3M12 3v5M4 5l1-2 1 2M11 5l1-2 1 2M18 5l1-2 1 2M10 17h4',
        'zeus' => 'M13 2L4 14h7l-1 8 9-12h-7z',
        'athena' => 'M5 9a7 7 0 0 1 14 0v5a7 7 0 0 1-14 0zM9.5 8a2 2 0 1 0 0 4 2 2 0 1 0 0-4zM14.5 8a2 2 0 1 0 0 4 2 2 0 1 0 0-4zM11 14l1 1.5 1-1.5M5 4l2.5 2.5M19 4l-2.5 2.5',
        'apollo' => 'M7 4c-2.5 3-2.5 8 1 11h8c3.5-3 3.5-8 1-11M8 15v5h8v-5M10 7v8M12 6v9M14 7v8M5 4h3M16 4h3',
        'artemis' => 'M15 3a9 9 0 1 0 6 15A7 7 0 0 1 15 3z',
        'hermes' => 'M12 3v18M12 7C9 7 6 6 3 3c1 4 4.5 6 9 6M12 7c3 0 6-1 9-4-1 4-4.5 6-9 6M9 13c2 1.3 4 1.3 6 0M9 17c2-1.3 4-1.3 6 0',
        'hephaestus' => 'M4 14h12l-2 3H7zM10 17v3M7 21h7M13 3l5 5-2 2-5-5zM14.5 8.5L9 14',
        'ares' => 'M20 4L9 15M16 4h4v4M9 13a5 5 0 1 0 0 8 5 5 0 0 0 0-8z',
        'dionysus' => 'M12 2v3M12 4c2-2 5-2 6 0M8 9a2 2 0 1 0 .01 0zM12 9a2 2 0 1 0 .01 0zM16 9a2 2 0 1 0 .01 0zM10 13a2 2 0 1 0 .01 0zM14 13a2 2 0 1 0 .01 0zM12 17a2 2 0 1 0 .01 0z',
    ];
@endphp

{{-- Gaia's family tree (About::family()), opened by the surprise in filament.about: each member on the GaiaCP hexagon
     with a pictogram, what the myths made them god of and the part of GaiaCP that does that job. --}}
<style>
    .gcp-family-backdrop { position: fixed; inset: 0; z-index: 65; display: flex; justify-content: center; align-items: flex-start; padding: 1rem; overflow-y: auto; background: rgb(3 15 12 / .72); backdrop-filter: blur(2px); }
    .gcp-family { position: relative; width: 100%; max-width: 64rem; margin: auto; padding: 1.75rem 1rem 2rem; border-radius: 1rem; text-align: center; color: var(--gray-950); background: radial-gradient(ellipse at top, #f2fbf7, #fff 60%); box-shadow: 0 25px 50px -12px rgb(0 0 0 / .45); }
    .dark .gcp-family { color: #fff; background: radial-gradient(ellipse at top, #12302a, var(--gray-900) 60%); }
    .gcp-family > h2 { font-size: 1.4rem; font-weight: 700; letter-spacing: -.01em; }
    .gcp-family > p { margin: .25rem auto 0; max-width: 32rem; font-size: .85rem; color: var(--gray-500); }
    .gcp-family-close { position: absolute; top: .6rem; inset-inline-end: .6rem; width: 2rem; height: 2rem; border-radius: 999px; font-size: 1.25rem; line-height: 1; color: var(--gray-500); }
    .gcp-family-close:hover { color: var(--gray-700); background: var(--gray-100); }
    .dark .gcp-family-close:hover { color: var(--gray-200); background: var(--gray-800); }
    .gcp-family-gen { position: relative; margin-top: 1.75rem; }
    .gcp-family-gen + .gcp-family-gen::before { content: ''; position: absolute; top: -1.6rem; left: 50%; width: 2px; height: 1.2rem; background: linear-gradient(#3aa58a, #e8c547); border-radius: 2px; }
    .gcp-family-caption { display: inline-block; margin-bottom: .75rem; padding: .15rem .7rem; border-radius: 999px; font-size: .7rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #1f7a64; background: #e3f5ee; }
    .dark .gcp-family-caption { color: #8fe0c6; background: rgb(58 165 138 / .15); }
    .gcp-family-row { display: flex; flex-wrap: wrap; justify-content: center; gap: 1rem .5rem; }
    .gcp-family-member { width: 7.75rem; opacity: 0; transform: translateY(.75rem); animation: gcp-family-in .5s ease forwards; }
    .gcp-family-hex { position: relative; display: block; width: 4.75rem; height: 4.75rem; margin: 0 auto .4rem; transition: transform .2s; }
    .gcp-family-member:hover .gcp-family-hex { transform: translateY(-3px) rotate(-4deg); }
    .gcp-family-hex img { width: 100%; height: 100%; filter: brightness(.82) saturate(1.1) drop-shadow(0 6px 8px rgb(0 0 0 / .25)); }
    .gcp-family-hex svg { position: absolute; inset: 22%; width: 56%; height: 56%; color: #fff; filter: drop-shadow(0 1px 2px rgb(0 0 0 / .6)); }
    .gcp-family-gaia .gcp-family-hex { width: 6rem; height: 6rem; }
    .gcp-family-gaia .gcp-family-hex img { filter: drop-shadow(0 6px 10px rgb(0 0 0 / .3)); animation: gcp-family-spin 18s linear infinite; }
    .gcp-family-member strong { display: block; font-size: .95rem; }
    .gcp-family-myth { display: block; font-size: .72rem; color: var(--gray-500); line-height: 1.25; }
    .gcp-family-job { display: block; margin-top: .25rem; font-size: .75rem; font-weight: 500; line-height: 1.25; color: var(--primary-600); }
    .dark .gcp-family-job { color: var(--primary-400); }
    @keyframes gcp-family-in { to { opacity: 1; transform: none; } }
    @keyframes gcp-family-spin { to { transform: rotate(360deg); } }
    @media (max-width: 640px) {
        .gcp-family-member { width: 6.75rem; }
        .gcp-family-hex { width: 4rem; height: 4rem; }
    }
    @media (prefers-reduced-motion: reduce) {
        .gcp-family-member { opacity: 1; transform: none; animation: none; }
        .gcp-family-gaia .gcp-family-hex img { animation: none; }
    }
</style>

<template x-teleport="body">
    <div
        x-show="family"
        x-transition.opacity.duration.400ms
        x-on:click.self="family = false"
        class="gcp-family-backdrop"
        style="display: none"
        role="dialog"
        aria-modal="true"
        aria-labelledby="gcp-family-title"
    >
        <div class="gcp-family">
            <button type="button" class="gcp-family-close" x-on:click="family = false" aria-label="{{ __('Close') }}">×</button>
            <h2 id="gcp-family-title">{{ __('Gaia\'s family') }}</h2>
            <p>{{ __('You found it. Meet the family GaiaCP is named after, and the part each one plays here.') }}</p>

            @php $delay = 0; @endphp
            @foreach (About::family() as $generation)
                <div class="gcp-family-gen">
                    <span class="gcp-family-caption">{{ __($generation['caption']) }}</span>
                    <div class="gcp-family-row">
                        @foreach ($generation['members'] as $member)
                            <div @class(['gcp-family-member', 'gcp-family-gaia' => $member['icon'] === 'gaia']) style="animation-delay: {{ number_format(($delay++) * 0.06, 2) }}s">
                                <span class="gcp-family-hex" aria-hidden="true">
                                    <img src="{{ asset('images/gaiacp/mark.svg') }}" alt="">
                                    @isset($icons[$member['icon']])
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icons[$member['icon']] }}"/></svg>
                                    @endisset
                                </span>
                                <strong>{{ __($member['name']) }}</strong>
                                <span class="gcp-family-myth">{{ __($member['myth']) }}</span>
                                <span class="gcp-family-job">{{ __($member['job']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</template>

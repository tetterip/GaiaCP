{{-- Top of every page of the public demo (App\Support\Demo): what it is, a countdown to the next reset, after which the page reloads, and a Star on GitHub button. --}}
@php
    $seconds = max(0, \App\Support\Demo::nextReset()->getTimestamp() - now()->getTimestamp());
@endphp
<div
    role="status"
    x-data="{
        left: {{ $seconds }},
        clock() { return Math.floor(this.left / 60) + ':' + String(this.left % 60).padStart(2, '0') },
    }"
    x-init="setInterval(() => { if (left > 0 && --left === 0) setTimeout(() => location.reload(), 15000) }, 1000)"
    style="padding: .5rem 1rem; text-align: center; font-size: .875rem; line-height: 1.4; background: color-mix(in oklab, var(--warning-500) 16%, transparent); border-bottom: 1px solid color-mix(in oklab, var(--warning-500) 35%, transparent);"
>
    <strong style="font-weight: 600;">{{ __('Demo') }}:</strong>
    {{ __('everything here is made up and put back every :minutes minutes.', ['minutes' => \App\Support\Demo::minutes()]) }}
    <span x-show="left > 0">{!! __('Next reset in :time.', ['time' => '<span x-text="clock()" style="font-variant-numeric: tabular-nums; font-weight: 600;"></span>']) !!}</span>
    <span x-show="left === 0" x-cloak>{{ __('Putting the demo back…') }}</span>
    <a class="gcp-demo-star" href="{{ \App\Support\Version::REPOSITORY_URL }}" target="_blank" rel="noopener" title="{{ __('Like GaiaCP? Open it on GitHub and press Star there.') }}" style="display: inline-flex; align-items: center; gap: .3rem; margin-inline-start: .5rem; padding: .15rem .65rem; border-radius: 999px; font-weight: 600; white-space: nowrap; color: #fff; background: #24292f; text-decoration: none;"><svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" style="width: .9rem; height: .9rem; color: #f1c40f;"><path d="M8 .25a.75.75 0 0 1 .673.418l1.882 3.815 4.21.612a.75.75 0 0 1 .416 1.279l-3.046 2.97.719 4.192a.75.75 0 0 1-1.088.791L8 12.347l-3.766 1.98a.75.75 0 0 1-1.088-.79l.72-4.194L.818 6.374a.75.75 0 0 1 .416-1.28l4.21-.611L7.327.668A.75.75 0 0 1 8 .25Z"/></svg>{{ __('Like it? Star GaiaCP on GitHub') }}</a>
</div>

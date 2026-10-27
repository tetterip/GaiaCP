{{-- Top of every page of the public demo (App\Support\Demo): what it is, and a countdown to the next reset, after which the page reloads. --}}
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
</div>

@props(['url'])
{{-- A link that shows its whole address: <wbr> breaks it every 20 characters, since some webmail (Yahoo) ignores word-break and scrolls sideways instead. One line: Markdown. --}}
<a href="{{ $url }}" style="word-break: break-all; overflow-wrap: anywhere;">{!! collect(mb_str_split($url, 20))->map(fn ($part) => e($part))->implode('<wbr>') !!}</a>

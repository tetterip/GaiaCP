{{-- /sitemap.xml (SitemapController): the public pages, each with its address in every language. --}}
{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($pages as $page)
    @foreach ($page['alternates'] ?: ['' => $page['url']] as $hreflang => $url)
        @continue($hreflang === 'x-default')
    <url>
        <loc>{{ $url }}</loc>
        @if ($page['modified'] ?? null)
        <lastmod>{{ $page['modified'] }}</lastmod>
        @endif
        @foreach ($page['alternates'] as $alternate => $href)
        <xhtml:link rel="alternate" hreflang="{{ $alternate }}" href="{{ $href }}"/>
        @endforeach
    </url>
    @endforeach
@endforeach
</urlset>

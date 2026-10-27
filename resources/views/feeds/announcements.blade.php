{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ $link }}</link>
        <description>{{ $title }}</description>
        <language>{{ app()->getLocale() }}</language>
        @foreach ($items as $item)
            <item>
                <title>{{ $item['title'] }}</title>
                <link>{{ $item['link'] }}</link>
                <guid isPermaLink="true">{{ $item['link'] }}</guid>
                <pubDate>{{ $item['date'] }}</pubDate>
                <description>{{ $item['description'] }}</description>
            </item>
        @endforeach
    </channel>
</rss>

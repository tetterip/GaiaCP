{{-- Open a ticket: knowledge base articles that match the subject (KnowledgeBase::suggestions()). --}}
<div class="gcp-kb-tips">
    <p class="gcp-kb-meta">{{ __('These articles may already answer it:') }}</p>
    @foreach ($articles as $article)
        <a class="gcp-kb-link" href="{{ \App\Filament\Client\Pages\KnowledgeBaseArticle::getUrl(['slug' => $article->slug]) }}" target="_blank" rel="noopener">{{ $article->title() }}</a>
    @endforeach
</div>

<?php

namespace App\Support;

use App\Models\KbArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The client area's knowledge base (App\Models\KbArticle): whether it shows, its search and an article's HTML. Visitors
 * get the articles not kept for clients (KbArticle::shown()).
 */
class KnowledgeBase
{
    /**
     * Settings → Support → Knowledge base is on and an article is published; for a visitor, also Settings → Public
     * area → Knowledge base, and an article not kept for clients.
     */
    public static function enabled(): bool
    {
        return (bool) config('gaiacp.support.knowledge_base')
            && (filament()->auth()->check() || PublicArea::knowledgeBase())
            && KbArticle::query()->shown()->exists();
    }

    /** @return Collection<int, KbArticle> published articles matching the words, best first (a word in the title wins) */
    public static function search(string $query, int $limit = 20): Collection
    {
        $words = collect(explode(' ', KbArticle::searchable($query)))->filter(fn (string $word) => mb_strlen($word) >= 2);

        return KbArticle::query()->shown()->matching($query)->with('category')->ordered()->limit(200)->get()
            ->sortByDesc(fn (KbArticle $article) => $words->filter(fn (string $word) => str_contains(KbArticle::searchable($article->title()), $word))->count())
            ->take($limit)
            ->values();
    }

    /** Articles to suggest for a ticket's subject: those matching any of its longer words. @return Collection<int, KbArticle> */
    public static function suggestions(?string $subject, int $limit = 3): Collection
    {
        if (! self::enabled() || mb_strlen(trim((string) $subject)) < 4) {
            return collect();
        }

        $words = collect(explode(' ', KbArticle::searchable((string) $subject)))->filter(fn (string $word) => mb_strlen($word) >= 4)->unique()->take(6);

        return $words->flatMap(fn (string $word) => self::search($word, $limit))
            ->countBy('id')->sortDesc()->keys()->take($limit)
            ->map(fn (int $id) => KbArticle::query()->find($id))->filter()->values();
    }

    /** The article's Markdown as HTML: raw HTML in it is stripped, unsafe links dropped. */
    public static function html(string $markdown): HtmlString
    {
        return new HtmlString(Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }
}

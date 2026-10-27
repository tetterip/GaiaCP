<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Support\KnowledgeBase as Articles;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The client area's Knowledge base (Support → Knowledge base): the published articles by category, and a search.
 * Visitors open it too while Settings → Public area says so, without the articles kept for clients.
 */
class KnowledgeBase extends Page
{
    use OpenToVisitors;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $title = 'Knowledge base';

    protected static ?string $slug = 'knowledgebase';

    protected static ?int $navigationSort = 80;

    /** Support, before Help. */
    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected string $view = 'filament.client.knowledge-base';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public static function canAccess(): bool
    {
        return Articles::enabled();
    }

    /** @return Collection<int, KbArticle> */
    public function results(): Collection
    {
        return Articles::search($this->search);
    }

    /** @return Collection<int, array{name: ?string, articles: Collection<int, KbArticle>}> the categories with published articles, then those without one */
    public function groups(): Collection
    {
        $articles = KbArticle::query()->shown()->ordered()->get()->groupBy(fn (KbArticle $article) => (int) $article->kb_category_id);
        $groups = KbCategory::query()->ordered()->get()
            ->filter(fn (KbCategory $category) => $articles->has($category->id))
            ->map(fn (KbCategory $category) => ['name' => $category->name(), 'articles' => $articles->get($category->id)])
            ->values();

        return $articles->has(0) ? $groups->push(['name' => $groups->isEmpty() ? null : __('Other articles'), 'articles' => $articles->get(0)]) : $groups;
    }

    public function articleUrl(KbArticle $article): string
    {
        return KnowledgeBaseArticle::getUrl(['slug' => $article->slug]);
    }
}

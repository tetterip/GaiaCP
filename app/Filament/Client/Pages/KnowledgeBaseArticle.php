<?php

namespace App\Filament\Client\Pages;

use App\Actions\ClientAreaAccess;
use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Models\KbArticle;
use App\Support\KnowledgeBase as Articles;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * One knowledge base article in the client area (/knowledgebase/{slug}): its text, "Was this helpful?" and others in
 * its category. A visitor opening one kept for clients is sent to the login, which brings them back to it.
 */
class KnowledgeBaseArticle extends Page
{
    use OpenToVisitors;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'knowledgebase-article';

    protected string $view = 'filament.client.knowledge-base-article';

    public KbArticle $article;

    public ?string $voted = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/knowledgebase/{slug}';
    }

    public static function canAccess(): bool
    {
        return Articles::enabled();
    }

    public function mount(string $slug): void
    {
        $this->article = KbArticle::query()->published()->where('slug', $slug)->firstOrFail();

        if (self::visitor() && $this->article->clients_only) {
            session()->put('url.intended', request()->fullUrl());
            $this->redirect(filament()->getLoginUrl());

            return;
        }
        $this->voted = session("kb.voted.{$this->article->id}");

        // Counted once a session, so reloading doesn't add up.
        if (! session()->has("kb.viewed.{$this->article->id}") && ! ClientAreaAccess::viewing()) {
            session()->put("kb.viewed.{$this->article->id}", true);
            $this->article->increment('views');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->article->title();
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [KnowledgeBase::getUrl() => __('Knowledge base')];
    }

    public function body(): HtmlString
    {
        return Articles::html($this->article->text()['body']);
    }

    /** @return Collection<int, KbArticle> up to 5 others in its category */
    public function related(): Collection
    {
        return $this->article->kb_category_id === null ? collect() : KbArticle::query()->shown()
            ->where('kb_category_id', $this->article->kb_category_id)->whereKeyNot($this->article->id)->ordered()->limit(5)->get();
    }

    public function ticketUrl(): ?string
    {
        if (self::visitor()) {
            return ContactSupport::canAccess() ? ContactSupport::getUrl() : null;
        }

        return TicketResource::canAccess() ? TicketResource::getUrl('create') : null;
    }

    public function articleUrl(KbArticle $article): string
    {
        return static::getUrl(['slug' => $article->slug]);
    }

    /** "Was this helpful?": one answer a session. */
    public function vote(bool $helpful): void
    {
        if ($this->voted !== null) {
            return;
        }

        $this->article->increment($helpful ? 'helpful_yes' : 'helpful_no');
        $this->voted = $helpful ? 'yes' : 'no';
        session()->put("kb.voted.{$this->article->id}", $this->voted);
    }
}

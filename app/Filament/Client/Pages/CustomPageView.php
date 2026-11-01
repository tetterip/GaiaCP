<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OpenToVisitors;
use App\Models\CustomPage;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A page of your own (Support → Pages, App\Models\CustomPage) at /pages/{slug}, for clients and visitors. A visitor
 * opening one kept for clients is sent to the login, which brings them back to it; a draft is not found.
 */
class CustomPageView extends Page
{
    use OpenToVisitors;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'custom-page';

    protected string $view = 'filament.client.custom-page';

    // A centred column like the contact page, so the card fits the text instead of leaving an empty band beside it
    protected Width|string|null $maxContentWidth = Width::FourExtraLarge;

    public CustomPage $page;

    public static function getRoutePath(Panel $panel): string
    {
        return '/pages/{slug}';
    }

    public static function canAccess(): bool
    {
        return true;
    }

    public static function urlFor(CustomPage $page): string
    {
        return self::getUrl(['slug' => $page->slug], panel: 'client');
    }

    public function mount(string $slug): void
    {
        $this->page = CustomPage::query()->published()->where('slug', $slug)->firstOrFail();

        if (self::visitor() && $this->page->clients_only) {
            session()->put('url.intended', request()->fullUrl());
            $this->redirect(filament()->getLoginUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->page->title();
    }

    /** For search engines and link previews (App\Support\Seo). */
    public function metaDescription(): ?string
    {
        return $this->page->description();
    }

    /** Its featured image for link previews (og:image), in place of the company logo. */
    public function metaImage(): ?string
    {
        return $this->page->imageUrl();
    }

    /** Kept for clients: out of search results. */
    public function noindex(): bool
    {
        return $this->page->clients_only;
    }
}

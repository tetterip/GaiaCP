<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OpenToVisitors;
use App\Models\Announcement;
use App\Providers\AppServiceProvider;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One announcement (/announcements/{slug}). A visitor opening one kept for clients is sent to the login, which brings
 * them back to it.
 */
class AnnouncementPage extends Page
{
    use OpenToVisitors;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'announcement';

    protected string $view = 'filament.client.announcement';

    public Announcement $announcement;

    public static function getRoutePath(Panel $panel): string
    {
        return '/announcements/{slug}';
    }

    public static function canAccess(): bool
    {
        return Announcements::canAccess() || self::visitor();
    }

    public function mount(string $slug): void
    {
        $this->announcement = Announcement::query()->published()->where('slug', $slug)->firstOrFail();

        if (self::visitor() && ($this->announcement->clients_only || ! Announcements::canAccess())) {
            session()->put('url.intended', request()->fullUrl());
            $this->redirect(filament()->getLoginUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->announcement->title();
    }

    public function getSubheading(): ?string
    {
        return $this->announcement->published_at?->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat());
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [Announcements::getUrl() => __('Announcements')];
    }
}

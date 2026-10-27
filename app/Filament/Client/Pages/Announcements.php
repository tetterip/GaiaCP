<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Announcement;
use App\Support\PublicArea;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\Paginator;
use Livewire\WithPagination;
use UnitEnum;

/**
 * The client area's Announcements (Support → Announcements in /admin): the published ones, newest first, a page at a
 * time. Visitors read the ones not kept for clients while Settings → Public area → Announcements is on; the same ones
 * are in the RSS feed (AnnouncementFeedController).
 */
class Announcements extends Page
{
    use OpenToVisitors;
    use TranslatesPageLabels;
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $title = 'Announcements';

    protected static ?string $slug = 'announcements';

    protected static ?int $navigationSort = 78;

    /** Support, before the knowledge base. */
    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected string $view = 'filament.client.announcements';

    public const PER_PAGE = 10;

    public static function canAccess(): bool
    {
        return (! self::visitor() || PublicArea::announcements()) && Announcement::query()->shown()->exists();
    }

    /** @return Paginator<int, Announcement> */
    public function announcements(): Paginator
    {
        return Announcement::query()->shown()->simplePaginate(self::PER_PAGE);
    }

    public function announcementUrl(Announcement $announcement): string
    {
        return AnnouncementPage::getUrl(['slug' => $announcement->slug]);
    }

    /** The RSS feed, while visitors may read them. */
    public function feedUrl(): ?string
    {
        return PublicArea::announcements() ? route('filament.client.announcements.feed') : null;
    }
}

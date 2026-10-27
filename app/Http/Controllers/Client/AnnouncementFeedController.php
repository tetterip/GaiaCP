<?php

namespace App\Http\Controllers\Client;

use App\Filament\Client\Pages\AnnouncementPage;
use App\Filament\Client\Pages\Announcements;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\PublicArea;
use Illuminate\Http\Response;

/** The announcements' RSS feed (/announcements/feed): the 20 newest not kept for clients, while visitors may read them. */
class AnnouncementFeedController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(PublicArea::announcements(), 404);

        $items = Announcement::query()->published()->where('clients_only', false)
            ->orderByDesc('published_at')->orderByDesc('id')->limit(20)->get();

        return response()
            ->view('feeds.announcements', [
                'title' => __(':company announcements', ['company' => config('gaiacp.company.name') ?: 'GaiaCP']),
                'link' => Announcements::getUrl(panel: 'client'),
                'items' => $items->map(fn (Announcement $item) => [
                    'title' => $item->title(),
                    'link' => AnnouncementPage::getUrl(['slug' => $item->slug], panel: 'client'),
                    'date' => $item->published_at->toRssString(),
                    'description' => $item->excerpt(500),
                ]),
            ])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}

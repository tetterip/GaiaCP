<?php

namespace App\Http\Controllers;

use App\Actions\ClientOrder;
use App\Filament\Client\Pages\AnnouncementPage;
use App\Filament\Client\Pages\Announcements;
use App\Filament\Client\Pages\ContactSupport;
use App\Filament\Client\Pages\DomainPrices;
use App\Filament\Client\Pages\KnowledgeBase;
use App\Filament\Client\Pages\KnowledgeBaseArticle;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Client\Pages\ServerStatus;
use App\Models\Announcement;
use App\Models\KbArticle;
use App\Support\PublicArea;
use App\Support\Seo;
use App\Support\StatusPage;
use Illuminate\Http\Response;

/**
 * /sitemap.xml and /robots.txt for search engines (App\Support\Seo): the public pages a visitor can open now, each in
 * every language, while Settings → Client area → Search engines lets them in; otherwise robots.txt keeps them out.
 */
class SitemapController extends Controller
{
    public function sitemap(): Response
    {
        abort_unless(Seo::open(), 404);

        $pages = collect($this->pages())->map(fn (array $page) => $page + ['alternates' => Seo::alternates($page['url'], [])]);

        return response()->view('seo.sitemap', ['pages' => $pages], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        if (Seo::open()) {
            foreach (['admin', 'install'] as $path) {
                $lines[] = 'Disallow: '.rtrim((string) parse_url(url($path), PHP_URL_PATH), '/').'/';
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.route('sitemap');
        } else {
            $lines[] = 'Disallow: /';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @return list<array{url: string, modified?: ?string}> what a visitor (nobody signed in) can open */
    private function pages(): array
    {
        $pages = [];

        if (PublicArea::home()) {
            $pages[] = ['url' => route('filament.client.home')];
        }

        if (PublicArea::domainSearch()) {
            $pages[] = ['url' => SearchDomains::getUrl(panel: 'client')];
        }

        if (PublicArea::domainPrices()) {
            $pages[] = ['url' => DomainPrices::getUrl(panel: 'client')];
        }

        if (ClientOrder::publicEnabled()) {
            $pages[] = ['url' => route('filament.client.cart')];
        }

        if (config('gaiacp.support.knowledge_base') && PublicArea::knowledgeBase()) {
            $articles = KbArticle::query()->published()->where('clients_only', false)->orderBy('id')->get();

            if ($articles->isNotEmpty()) {
                $pages[] = ['url' => KnowledgeBase::getUrl(panel: 'client'), 'modified' => $articles->max('updated_at')?->toAtomString()];
            }

            foreach ($articles as $article) {
                $pages[] = ['url' => KnowledgeBaseArticle::getUrl(['slug' => $article->slug], panel: 'client'), 'modified' => $article->updated_at?->toAtomString()];
            }
        }

        if (PublicArea::announcements()) {
            $announcements = Announcement::query()->published()->where('clients_only', false)->orderByDesc('published_at')->get();

            if ($announcements->isNotEmpty()) {
                $pages[] = ['url' => Announcements::getUrl(panel: 'client'), 'modified' => $announcements->max('updated_at')?->toAtomString()];
            }

            foreach ($announcements as $announcement) {
                $pages[] = ['url' => AnnouncementPage::getUrl(['slug' => $announcement->slug], panel: 'client'), 'modified' => $announcement->updated_at?->toAtomString()];
            }
        }

        if (StatusPage::enabled()) {
            $pages[] = ['url' => ServerStatus::getUrl(panel: 'client')];
        }

        if (PublicArea::contact() || PublicArea::contactDetails()) {
            $pages[] = ['url' => ContactSupport::getUrl(panel: 'client')];
        }

        return $pages;
    }
}

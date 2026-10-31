<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Client;
use App\Models\Download;
use App\Models\DownloadCategory;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Support → Downloads: the files of Support → Downloads (admin) the visitor or client may have, by category. Shown
 * while there is one: visitors see it with a file for everyone, clients with one for them or their products.
 */
class Downloads extends Page
{
    use OpenToVisitors;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $title = 'Downloads';

    protected static ?string $slug = 'downloads';

    protected static ?int $navigationSort = 82;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected string $view = 'filament.client.downloads';

    public static function canAccess(): bool
    {
        return Download::query()->visibleTo(self::client())->exists();
    }

    /** @return Collection<int, array{name: ?string, downloads: Collection<int, Download>}> the categories with files, then those without one */
    public function groups(): Collection
    {
        $downloads = Download::query()->visibleTo(self::client())->ordered()->get()->groupBy(fn (Download $download) => (int) $download->download_category_id);
        $groups = DownloadCategory::query()->ordered()->get()
            ->filter(fn (DownloadCategory $category) => $downloads->has($category->id))
            ->map(fn (DownloadCategory $category) => ['name' => $category->name(), 'downloads' => $downloads->get($category->id)])
            ->values();

        return $downloads->has(0) ? $groups->push(['name' => $groups->isEmpty() ? null : __('Other files'), 'downloads' => $downloads->get(0)]) : $groups;
    }

    public function fileUrl(Download $download): string
    {
        return route('filament.client.download-file', ['download' => $download->id]);
    }

    private static function client(): ?Client
    {
        return filament()->auth()->user()?->client;
    }
}

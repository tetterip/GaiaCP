<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\InstallsCataloguePlugins;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Plugins\Catalogue\Listing;
use App\Plugins\Catalogue\ListingVersion;
use App\Plugins\Catalogue\Tags;
use App\Providers\AppServiceProvider;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * One plugin of the catalogue on its own page (Settings → Plugin catalogue → a plugin): what it does, its tags, every
 * version's changelog and what it needs, with Install / Update. Read from the stored catalogue, so opening it makes no
 * request; a plugin no longer listed is a 404.
 */
class PluginCatalogueEntry extends Page
{
    use InstallsCataloguePlugins;
    use LimitedToAdminArea;

    protected static ?string $cluster = SettingsCluster::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'plugins/catalogue/{folder}';

    public string $folder;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'plugins.catalogue.entry';
    }

    public function mount(string $folder): void
    {
        abort_unless(static::canAccess(), 403); // before the 404, so others don't learn which plugins are listed
        $listing = $this->data()?->find($folder);
        abort_if($listing === null, 404);

        $this->folder = $listing->folder;
    }

    protected function cataloguePlugin(?array $record = null): Listing
    {
        $listing = $this->data()?->find($this->folder);
        abort_if($listing === null, 404);

        return $listing;
    }

    protected function afterCatalogueInstall(): void
    {
        // the page reads the plugin's status on each render
    }

    public function getTitle(): string|Htmlable
    {
        return $this->cataloguePlugin()->name;
    }

    public function getSubheading(): ?string
    {
        return $this->cataloguePlugin()->description();
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            SettingsCluster::getUrl() => __('Settings'),
            PluginCatalogue::getUrl() => __('Plugin catalogue'),
            $this->cataloguePlugin()->name,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->installAction('install'),
            $this->installAction('update'),
            Action::make('publicPage')
                ->label('Public page')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                // the catalogue builder writes plugins/<Folder>/ next to catalogue.json
                ->url(fn () => rtrim((string) config('gaiacp.plugins.catalogue.url'), '/').'/plugins/'.rawurlencode($this->folder).'/', shouldOpenInNewTab: true),
            Action::make('website')
                ->label('Website')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn () => $this->cataloguePlugin()->homepage, shouldOpenInNewTab: true)
                ->visible(fn () => filled($this->cataloguePlugin()->homepage)),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $listing = $this->cataloguePlugin();
        $row = $this->row($listing);
        $version = $listing->best() ?? $listing->latest();

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Grid::make(1)->columnSpan(['lg' => 2])->schema([
                    Section::make()
                        ->schema([
                            Text::make($this->communityNote())->color('warning'),
                        ])
                        ->visible(! $listing->official),
                    Section::make(__('Changelog'))
                        ->schema(collect($listing->versions)
                            ->map(fn (ListingVersion $v) => Text::make(new HtmlString('<div class="fi-prose"><h3>'.e($v->version)
                                .($v->releasedAt ? ' <small style="font-weight: normal; color: var(--gray-500)">'.e($this->date($v)).'</small>' : '').'</h3>'
                                .Str::markdown($v->changelog ?: '-', ['html_input' => 'strip', 'allow_unsafe_links' => false])
                                .($v->runsHere() ? '' : '<p style="color: var(--gray-500)">'.e(__('Needs :needs', ['needs' => $v->missing()])).'</p>')
                                .'</div>')))
                            ->all()),
                ]),
                Section::make(__('Details'))
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')->label('Status')->state($row['status'])->badge()->color(self::statusColor($row['status_key'])),
                        TextEntry::make('version')->label('Version')->state($version->version.($version->releasedAt ? ' · '.$this->date($version) : '')),
                        TextEntry::make('installed')->label('Installed here')->state($row['installed'] ?? __('No')),
                        TextEntry::make('needs')->label('Needs')->state('GaiaCP '.($version->requires ?? '0').'+'.($version->php ? ', PHP '.$version->php.'+' : '')),
                        TextEntry::make('source')->label('Source')->state($listing->official ? __('Official') : __('Community'))
                            ->badge()->color($listing->official ? 'success' : 'gray'),
                        TextEntry::make('type')->label('Type')->state($row['category']),
                        TextEntry::make('tags')->label('Tags')
                            ->state($listing->tags)
                            ->formatStateUsing(fn (string $state) => Tags::label($state))
                            ->badge()->color('info')
                            ->url(fn (string $state) => PluginCatalogue::getUrl(['tag' => $state]))
                            ->visible($listing->tags !== []),
                        TextEntry::make('author')->label('Author')->state($listing->author)
                            ->url($listing->authorUrl, shouldOpenInNewTab: true),
                        TextEntry::make('licence')->label('Licence')->state($listing->licence)->visible(filled($listing->licence)),
                    ]),
            ]),
        ]);
    }

    private function date(ListingVersion $version): string
    {
        return $version->releasedAt->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat());
    }
}

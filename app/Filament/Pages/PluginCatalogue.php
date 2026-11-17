<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\InstallsCataloguePlugins;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Plugins\Catalogue\Catalogue;
use App\Plugins\Catalogue\CatalogueException;
use App\Plugins\Catalogue\Listing;
use App\Plugins\Catalogue\Tags;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Settings → Plugins → Plugin catalogue: the plugins on offer in the signed catalogue (App\Plugins\Catalogue, by
 * default plugins.gaiacp.digital), with Install and Update. It lists the last copy read (daily by
 * gaiacp:check-plugin-catalogue, or Refresh); installing downloads the newest version that runs here, checks it
 * against the catalogue and installs it as an uploaded plugin (CatalogueInstaller → PluginInstaller), so it starts
 * disabled and can be uninstalled on the Plugins page. Super admins, with their password, like Upload plugin. Each
 * plugin has its own page (PluginCatalogueEntry); tags and types filter the list.
 */
class PluginCatalogue extends Page implements HasTable
{
    use InstallsCataloguePlugins;
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 91;

    protected static ?string $title = 'Plugin catalogue';

    protected static ?string $slug = 'plugins/catalogue';

    /** Why the catalogue could not be read on this visit, shown under the title. */
    public ?string $problem = null;

    public function mount(): void
    {
        // a tag on a plugin's page links here with ?tag=
        if (is_string($tag = request()->query('tag')) && $tag !== '') {
            $this->tableFilters['tag']['values'] = [$tag];
        }

        if (Catalogue::enabled() && $this->catalogue()->stored() === null) {
            $this->readCatalogue(quiet: true);
        }
    }

    public function getSubheading(): ?string
    {
        if (! Catalogue::enabled()) {
            return __('The plugin catalogue is turned off on this installation.');
        }

        $stored = $this->catalogue()->stored();

        return collect([
            __('Reviewed plugins from :url. Installing one adds it disabled, like an uploaded zip: enable it on the Plugins page when you are ready.', ['url' => config('gaiacp.plugins.catalogue.url')]),
            $stored && ($read = $this->catalogue()->readAt())
                ? __('Last read :date.', ['date' => $read->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat().' H:i')])
                : null,
            $this->problem,
        ])->filter()->implode(' ');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn () => Catalogue::enabled())
                ->action(fn () => $this->readCatalogue()),
            Action::make('plugins')
                ->label('Installed plugins')
                ->icon(Heroicon::OutlinedPuzzlePiece)
                ->color('gray')
                ->url(fn () => Plugins::getUrl()),
        ];
    }

    private function readCatalogue(bool $quiet = false): void
    {
        try {
            $this->catalogue()->fetch();
            $this->problem = null;
        } catch (CatalogueException $e) {
            $this->problem = $e->getMessage();
            if (! $quiet) {
                Notification::make()->danger()->title(__('The catalogue was not read'))->body($e->getMessage())->send();
            }

            return;
        }

        $this->flushCachedTableRecords();
        if (! $quiet) {
            Notification::make()->success()->title(__('Catalogue read'))->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?array $filters, ?string $search) => $this->rows($filters ?? [], $search))
            ->searchable()
            ->filters([
                SelectFilter::make('tag')
                    ->label('Tags')
                    ->multiple()
                    ->options(fn () => Tags::options($this->data()->plugins ?? [])),
                SelectFilter::make('type')
                    ->label('Type')
                    ->multiple()
                    ->options(fn () => collect($this->data()->plugins ?? [])->pluck('category')->unique()
                        ->mapWithKeys(fn (string $category) => [$category => Plugins::categoryLabel($category)])
                        ->sort()->all()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'installed' => __('Installed'),
                        'update' => __('Update available'),
                        'available' => __('Not installed'),
                    ]),
            ])
            ->emptyStateHeading(fn () => Catalogue::enabled() ? __('No plugins to show') : __('The plugin catalogue is turned off on this installation.'))
            ->emptyStateDescription(fn () => $this->problem)
            ->columns([
                Split::make([
                    TextColumn::make('name')
                        ->label('Plugin')
                        ->weight('medium')
                        ->description(fn (array $record) => self::details($record))
                        ->wrap(),
                    TextColumn::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (array $record) => self::statusColor($record['status_key']))
                        ->grow(false),
                ])->from('md'),
            ])
            ->paginated(false)
            ->recordUrl(fn (array $record) => PluginCatalogueEntry::getUrl(['folder' => $record['folder']]))
            ->recordActions([
                Action::make('open')
                    ->iconButton()
                    ->tooltip(__('Open the plugin\'s page'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->url(fn (array $record) => PluginCatalogueEntry::getUrl(['folder' => $record['folder']])),
                $this->installAction('install'),
                $this->installAction('update'),
            ]);
    }

    /**
     * The table's rows: official plugins first, then by name.
     *
     * @param  array<string, array<string, mixed>>  $filters
     * @return array<string, array<string, mixed>>
     */
    private function rows(array $filters, ?string $search): array
    {
        $tags = array_filter((array) ($filters['tag']['values'] ?? []));
        $types = array_filter((array) ($filters['type']['values'] ?? []));
        $status = $filters['status']['value'] ?? null;
        $search = Str::lower(trim((string) $search));

        return collect($this->data()->plugins ?? [])
            ->filter(fn (Listing $listing) => $tags === [] || array_intersect($tags, $listing->tags) !== [])
            ->filter(fn (Listing $listing) => $types === [] || in_array($listing->category, $types, true))
            ->filter(fn (Listing $listing) => $search === '' || Str::contains(Str::lower(implode(' ', [
                $listing->name, $listing->folder, $listing->author, $listing->category, Plugins::categoryLabel($listing->category),
                ...array_values($listing->description), ...$listing->tags, ...array_map(Tags::label(...), $listing->tags),
            ])), $search))
            ->mapWithKeys(fn (Listing $listing) => [$listing->folder => $this->row($listing)])
            ->filter(fn (array $row) => blank($status) || match ($status) {
                'installed' => in_array($row['status_key'], ['installed', 'bundled', 'update'], true),
                default => $row['status_key'] === $status,
            })
            ->sortBy(fn (array $row) => [! $row['official'], Str::lower($row['name'])])
            ->all();
    }

    /** Under the name: who made it, its type and version, then what it does. */
    private static function details(array $record): HtmlString
    {
        $badge = fn (string $color, string $label, ?string $icon = null) => Blade::render('<x-filament::badge :color="$color" :icon="$icon" size="sm">{{ $label }}</x-filament::badge>', compact('color', 'label', 'icon'));

        return new HtmlString('<span style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin: 0.25rem 0 0.375rem">'
            .$badge('primary', 'v'.$record['version'], 'heroicon-o-tag')
            .$badge($record['official'] ? 'success' : 'gray', $record['official'] ? __('Official') : __('Community'))
            .$badge('gray', $record['category'])
            .collect($record['tags'])->map(fn (string $tag) => $badge('info', $tag, 'heroicon-o-hashtag'))->implode('')
            .'<span>'.e(__('By :author', ['author' => $record['author']])).'</span>'
            .'</span>'.e($record['description']));
    }

    protected function cataloguePlugin(?array $record): Listing
    {
        $listing = $this->data()?->find((string) ($record['folder'] ?? ''));
        abort_if($listing === null, 404);

        return $listing;
    }

    protected function afterCatalogueInstall(): void
    {
        $this->redirect(static::getUrl()); // a fresh page: this request's panel predates the new plugin
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}

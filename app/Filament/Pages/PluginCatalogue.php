<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Plugins\Catalogue\Catalogue;
use App\Plugins\Catalogue\CatalogueData;
use App\Plugins\Catalogue\CatalogueException;
use App\Plugins\Catalogue\CatalogueInstaller;
use App\Plugins\Catalogue\Listing;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use App\Support\Translations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
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
 * disabled and can be uninstalled on the Plugins page. Super admins, with their password, like Upload plugin.
 */
class PluginCatalogue extends Page implements HasTable
{
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

    private function catalogue(): Catalogue
    {
        return app(Catalogue::class);
    }

    private function installer(): CatalogueInstaller
    {
        return app(CatalogueInstaller::class);
    }

    private function data(): ?CatalogueData
    {
        return Catalogue::enabled() ? $this->catalogue()->stored() : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search) => collect($this->data()?->plugins ?? [])
                ->filter(fn (Listing $listing) => blank($search) || Str::contains(
                    Str::lower(implode(' ', [$listing->name, $listing->folder, $listing->author, $listing->category, ...array_values($listing->description)])),
                    Str::lower(trim($search))))
                ->mapWithKeys(fn (Listing $listing) => [$listing->folder => $this->row($listing)])
                ->sortBy(fn (array $row) => [! $row['official'], Str::lower($row['name'])])
                ->all())
            ->searchable()
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
                        ->color(fn (array $record) => match ($record['status_key']) {
                            'installed' => 'success',
                            'update' => 'warning',
                            'incompatible' => 'danger',
                            default => 'gray',
                        })
                        ->grow(false),
                ])->from('md'),
            ])
            ->paginated(false)
            ->recordActions([
                Action::make('details')
                    ->iconButton()
                    ->tooltip(__('Details'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->modalHeading(fn (array $record) => $record['name'])
                    ->modalContent(fn (array $record) => $this->detailsModal($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->slideOver(),
                $this->installAction('install')
                    ->label('Install')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (array $record) => $record['status_key'] === 'available' && PluginInstaller::uploadsAllowed()),
                $this->installAction('update')
                    ->label('Update')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (array $record) => $record['status_key'] === 'update' && PluginInstaller::uploadsAllowed()),
            ]);
    }

    /** @return array<string, mixed> */
    private function row(Listing $listing): array
    {
        $installed = $this->installer()->installed($listing);
        $best = $listing->best();
        $update = $this->installer()->updateFor($listing);

        [$key, $status] = match (true) {
            $installed !== null && app(PluginManager::class)->bundled($installed) => ['bundled', __('Bundled')],
            $update !== null => ['update', __('Update to :version', ['version' => $update->version])],
            $installed !== null => ['installed', __('Installed')],
            $best === null => ['incompatible', __('Needs GaiaCP :version', ['version' => $listing->latest()->requires])],
            default => ['available', __('Not installed')],
        };

        return [
            'folder' => $listing->folder,
            'name' => $listing->name,
            'description' => $listing->description(),
            'official' => $listing->official,
            'category' => Plugins::categoryLabel($listing->category),
            'author' => $listing->author,
            'version' => ($best ?? $listing->latest())->version,
            'installed' => $installed?->version(),
            'status_key' => $key,
            'status' => $status,
        ];
    }

    /** Under the name: who made it, its type and version, then what it does. */
    private static function details(array $record): HtmlString
    {
        $badge = fn (string $color, string $label, ?string $icon = null) => Blade::render('<x-filament::badge :color="$color" :icon="$icon" size="sm">{{ $label }}</x-filament::badge>', compact('color', 'label', 'icon'));

        return new HtmlString('<span style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin: 0.25rem 0 0.375rem">'
            .$badge('primary', 'v'.$record['version'], 'heroicon-o-tag')
            .$badge($record['official'] ? 'success' : 'gray', $record['official'] ? __('Official') : __('Community'))
            .$badge('gray', $record['category'])
            .'<span>'.e(__('By :author', ['author' => $record['author']])).'</span>'
            .'</span>'.e($record['description']));
    }

    private function detailsModal(array $record): HtmlString
    {
        $listing = $this->listing($record);
        $version = $listing->best() ?? $listing->latest();

        $facts = collect([
            __('Version') => $version->version.($version->releasedAt ? ' · '.$version->releasedAt->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat()) : ''),
            __('Needs') => 'GaiaCP '.($version->requires ?? '0').'+'.($version->php ? ', PHP '.$version->php.'+' : ''),
            __('Installed here') => $record['installed'] ?? __('No'),
            __('Author') => $listing->author,
            __('Licence') => $listing->licence ?? '',
        ])->filter(fn (string $value) => $value !== '')
            ->map(fn (string $value, string $label) => '<dt style="color: var(--gray-500)">'.e($label).'</dt><dd style="margin: 0 0 0.5rem">'.e($value).'</dd>')
            ->implode('');

        $links = collect([__('Website') => $listing->homepage, __('Author\'s site') => $listing->authorUrl])->filter()
            ->map(fn (string $url, string $label) => '<a href="'.e($url).'" target="_blank" rel="noopener nofollow" style="text-decoration: underline">'.e($label).'</a>')
            ->implode(' · ');

        $changelog = collect($listing->versions)->take(5)
            ->map(fn ($v) => '<h3>'.e($v->version).'</h3>'.Str::markdown($v->changelog ?: '-', ['html_input' => 'strip', 'allow_unsafe_links' => false]))
            ->implode('');

        $note = $listing->official ? '' : '<p>'.e(__('A community plugin: written by someone else and reviewed before it was listed. Reviewing is not a guarantee, so install it only if you trust its author.')).'</p>';

        return new HtmlString('<div class="fi-prose"><p>'.e($listing->description()).'</p>'.$note.'<dl>'.$facts.'</dl>'
            .($links !== '' ? '<p>'.$links.'</p>' : '').'<h2>'.e(__('Changelog')).'</h2>'.$changelog.'</div>');
    }

    private function installAction(string $name): Action
    {
        return Action::make($name)
            ->modalHeading(fn (array $record) => $name === 'update'
                ? __('Update :plugin to :version?', ['plugin' => $record['name'], 'version' => $record['version']])
                : __('Install :plugin :version?', ['plugin' => $record['name'], 'version' => $record['version']]))
            ->modalDescription(fn (array $record) => collect([
                $record['official'] ? null : __('A community plugin: written by someone else and reviewed before it was listed. Reviewing is not a guarantee, so install it only if you trust its author.'),
                __('A plugin runs with full access to GaiaCP, its database and your clients\' data.'),
                $name === 'update' ? __('The version installed now is kept and put back if the update fails.') : __('It is added disabled.'),
            ])->filter()->implode(' '))
            ->schema([
                TextInput::make('password')
                    ->label('Your password')
                    ->password()
                    ->autocomplete('current-password')
                    ->required()
                    ->currentPassword(),
            ])
            ->modalSubmitActionLabel(fn () => $name === 'update' ? __('Update') : __('Install'))
            ->action(function (array $record, Action $action) {
                abort_unless(static::canAccess() && PluginInstaller::uploadsAllowed(), 403); // installs code: checked again on the request that does it

                try {
                    $plugin = $this->installer()->install($this->listing($record));
                } catch (CatalogueException|PluginInstallException $e) {
                    Notification::make()->danger()->persistent()->title(__('The plugin was not installed'))->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                $this->flushCachedTableRecords();
                $plugins = app(PluginManager::class);
                Notification::make()->success()
                    ->title(filled($plugins->installed($plugin)?->previous)
                        ? __(':plugin updated to :version', ['plugin' => $plugin->name(), 'version' => $plugin->version()])
                        : __(':plugin :version installed', ['plugin' => $plugin->name(), 'version' => $plugin->version()]))
                    ->body(collect([
                        $plugins->enabled($plugin) ? null : __('Enable it on the Plugins page when you are ready to use it.'),
                        ...collect(Translations::missingForPlugin($plugin))->map(fn (int $count, string $language) => trans_choice(
                            '{1} 1 new text is not in :language yet (Settings → Languages).|[2,*] :count new texts are not in :language yet (Settings → Languages).',
                            $count, ['language' => $language])),
                    ])->filter()->implode(' ') ?: null)
                    ->actions([Action::make('plugins')->label(__('Plugins'))->url(Plugins::getUrl())])
                    ->send();
            });
    }

    private function listing(array $record): Listing
    {
        $listing = $this->data()?->find($record['folder']);
        abort_if($listing === null, 404);

        return $listing;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}

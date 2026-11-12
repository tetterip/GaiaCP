<?php

namespace App\Filament\Pages;

use App\Filament\Actions\BulkOutcome;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Plugins\Catalogue\Catalogue;
use App\Plugins\Catalogue\CatalogueInstaller;
use App\Plugins\Catalogue\ListingVersion;
use App\Plugins\Plugin;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use App\Plugins\PluginState;
use App\Providers\AppServiceProvider;
use App\Support\Demo;
use App\Support\Translations;
use App\Support\Version;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Settings → System → Plugins: the server panels, registrars and integrations (App\Plugins\PluginManager), each
 * switched on or off. Off keeps its servers, accounts and settings; the bundled plugins can't be uninstalled.
 * Each shows its version (plugin.json), since when it is installed here and its CHANGELOG.md. Upload plugin installs
 * or updates one from a zip (PluginInstaller, your password asked: it is code with full access); those, unlike the
 * bundled ones, can be rolled back to the version an update replaced, and uninstalled once disabled.
 */
class Plugins extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Plugins';

    public static function settingsDescription(): string
    {
        return __('Server panels, registrars and integrations: turn off what you don\'t use.');
    }

    public function getSubheading(): ?string
    {
        $problems = collect($this->plugins()->problems())
            ->map(fn (string $problem, string $folder) => __('plugins/:folder was not loaded: :problem.', ['folder' => $folder, 'problem' => $problem]));

        return collect([
            __('Disabling a plugin keeps its servers, accounts and settings; enable it again to pick up where it left off.'),
            __('The bundled plugins update together with GaiaCP (this is :version).', ['version' => Version::current()]),
        ])->merge($problems)->implode(' ');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('catalogue')
                ->label('Plugin catalogue')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->visible(fn () => Catalogue::enabled() && PluginCatalogue::canAccess())
                ->badge(fn () => count($this->catalogueUpdates()) ?: null)
                ->badgeColor('warning')
                ->url(fn () => PluginCatalogue::getUrl()),
            Action::make('upload')
                ->label('Upload plugin')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn () => PluginInstaller::uploadsAllowed())
                ->modalHeading(__('Upload a plugin'))
                ->modalDescription(__('A plugin zip installs a new plugin, or updates one you uploaded before to a newer version. A new plugin starts disabled.'))
                ->schema([
                    FileUpload::make('zip')
                        ->label('Plugin zip')
                        ->required()
                        ->disk('local')
                        ->directory('plugin-uploads')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'multipart/x-zip'])
                        ->maxSize((int) config('gaiacp.plugins.max_upload_kb', 10240)),
                    Checkbox::make('trusted')
                        ->label('I trust this plugin')
                        ->helperText(__('A plugin is PHP code that runs with full access to GaiaCP, its database and your clients\' data. Only upload plugins from people you trust.'))
                        ->accepted(),
                    TextInput::make('password')
                        ->label('Your password')
                        ->password()
                        ->autocomplete('current-password')
                        ->required()
                        ->currentPassword(),
                ])
                ->modalSubmitActionLabel(__('Install'))
                ->action(function (array $data, Action $action) {
                    abort_unless(static::canAccess(), 403); // installs code: checked again on the request that does it
                    $zip = Storage::disk('local')->path($data['zip']);

                    try {
                        $plugin = app(PluginInstaller::class)->install($zip);
                        $previous = $this->plugins()->installed($plugin)?->previous;
                    } catch (PluginInstallException $e) {
                        Notification::make()->danger()->persistent()->title(__('The plugin was not installed'))->body($e->getMessage())->send();
                        $action->halt();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['zip']);
                    }

                    $this->flushCachedTableRecords();
                    Notification::make()->success()
                        ->title(filled($previous)
                            ? __(':plugin updated to :version', ['plugin' => $plugin->name(), 'version' => $plugin->version()])
                            : __(':plugin :version installed', ['plugin' => $plugin->name(), 'version' => $plugin->version()]))
                        ->body(collect([
                            $this->plugins()->enabled($plugin) ? null : __('Enable it below when you are ready to use it.'),
                            ...collect(Translations::missingForPlugin($plugin))->map(fn (int $count, string $language) => trans_choice(
                                '{1} 1 new text is not in :language yet (Settings → Languages).|[2,*] :count new texts are not in :language yet (Settings → Languages).',
                                $count, ['language' => $language])),
                        ])->filter()->implode(' ') ?: null)
                        ->send();
                }),
        ];
    }

    public function mount(): void
    {
        // Normally noted by migrate; also here in case a deploy skipped it.
        $this->plugins()->recordVersions();
    }

    private function plugins(): PluginManager
    {
        return app(PluginManager::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?array $filters, ?string $search) => $this->rows($filters ?? [], $search))
            ->searchable()
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->multiple()
                    ->options(fn () => collect(self::CATEGORIES)
                        ->filter(fn (string $category) => $this->plugins()->all()->contains(fn (Plugin $plugin) => $plugin->category() === $category))
                        ->mapWithKeys(fn (string $category) => [$category => self::categoryLabel($category)])
                        ->all()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'enabled' => __('Enabled'),
                        'disabled' => __('Disabled'),
                    ]),
                // Only once a plugin was uploaded: until then every plugin is bundled
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'bundled' => __('Bundled'),
                        'uploaded' => __('Uploaded'),
                    ])
                    ->visible(fn () => $this->plugins()->all()->contains(fn (Plugin $plugin) => ! $this->plugins()->bundled($plugin))),
            ])
            ->deferFilters(false)
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->emptyStateHeading(__('No plugins to show'))
            ->emptyStateDescription(__('No plugin matches the search or the filters.'))
            // Side by side from md; on a phone the name, version and description take the full width, the status under them
            ->columns([
                Split::make([
                    TextColumn::make('name')
                        ->label('Plugin')
                        ->weight('medium')
                        ->description(fn (array $record) => self::versionAndDescription($record))
                        ->wrap(),
                    TextColumn::make('state')
                        ->label('Status')
                        ->badge()
                        ->grow(false),
                ])->from('md'),
            ])
            ->defaultGroup(Group::make('category')->label('Type')->titlePrefixedWithLabel(false))
            ->groupingSettingsHidden()
            ->paginated(false)
            ->recordActions([
                Action::make('changelog')
                    ->label('Changelog')
                    ->iconButton()
                    ->tooltip(__('Changelog'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->visible(fn (array $record) => filled($this->plugin($record)->changelog()))
                    ->modalHeading(fn (array $record) => __(':plugin changelog', ['plugin' => $record['name']]))
                    ->modalContent(fn (array $record) => new HtmlString('<div class="fi-prose">'.Str::markdown(self::withoutTitle($this->plugin($record)->changelog()), ['html_input' => 'strip', 'allow_unsafe_links' => false]).'</div>'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->slideOver(),
                Action::make('settings')
                    ->iconButton()
                    ->tooltip(__('Settings'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->color('gray')
                    ->visible(fn (array $record) => $record['state'] === PluginState::Enabled && $this->plugin($record)->settingsPage())
                    ->url(fn (array $record) => $this->plugin($record)->settingsPage()::getUrl()),
                Action::make('disable')
                    ->label('Disable')
                    ->icon(Heroicon::OutlinedPause)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => __('Disable :plugin?', ['plugin' => $record['name']]))
                    ->modalDescription(__('Its servers, accounts and settings stay. Nothing new can use it, and automation that reaches it stops with an error in the activity log until you enable it again.'))
                    ->visible(fn (array $record) => $record['state'] === PluginState::Enabled && $record['id'] !== 'demo')
                    ->action(function (array $record) {
                        $this->plugins()->disable($this->plugin($record));
                        $this->flushCachedTableRecords();
                        Notification::make()->success()->title(__(':plugin disabled', ['plugin' => $record['name']]))->send();
                    }),
                Action::make('enable')
                    ->label('Enable')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('success')
                    ->visible(fn (array $record) => $record['state'] === PluginState::Disabled)
                    ->action(function (array $record) {
                        $this->plugins()->enable($this->plugin($record));
                        $this->flushCachedTableRecords();
                        Notification::make()->success()->title(__(':plugin enabled', ['plugin' => $record['name']]))->send();
                    }),
                // Only uploaded plugins that were updated here: the update kept the folder it replaced
                Action::make('roll_back')
                    ->iconButton()
                    ->tooltip(fn (array $record) => __('Roll back to :version', ['version' => $this->previousVersion($record)]))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (array $record) => ! $record['bundled'] && PluginInstaller::uploadsAllowed() && $this->previousVersion($record) !== null)
                    ->modalHeading(fn (array $record) => __('Roll :plugin back to :version?', ['plugin' => $record['name'], 'version' => $this->previousVersion($record)]))
                    ->modalDescription(__('The version it was updated from is put back; its switch and settings stay. What the newer version added to the database is removed, with anything stored there.'))
                    ->schema([
                        TextInput::make('password')
                            ->label('Your password')
                            ->password()
                            ->autocomplete('current-password')
                            ->required()
                            ->currentPassword(),
                    ])
                    ->modalSubmitActionLabel(__('Roll back'))
                    ->action(function (array $record, Action $action) {
                        abort_unless(static::canAccess(), 403); // puts code back: checked again on the request that does it

                        try {
                            $plugin = app(PluginInstaller::class)->rollBack($this->plugin($record));
                        } catch (PluginInstallException $e) {
                            Notification::make()->danger()->persistent()->title(__('The plugin was not rolled back'))->body($e->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        $this->flushCachedTableRecords();
                        Notification::make()->success()->title(__(':plugin rolled back to :version', ['plugin' => $plugin->name(), 'version' => $plugin->version()]))->send();
                    }),
                // Only uploaded plugins, once disabled: the bundled ones can't be uninstalled (project rule)
                Action::make('uninstall')
                    ->iconButton()
                    ->tooltip(__('Uninstall'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (array $record) => ! $record['bundled'] && $record['state'] !== PluginState::Enabled && ! Demo::enabled())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => __('Uninstall :plugin?', ['plugin' => $record['name']]))
                    ->modalDescription(__('Its folder is removed from plugins/. Its tables and settings stay unless you tick the box, so installing it again picks up where it left off.'))
                    ->schema([
                        Checkbox::make('delete_data')->label('Also delete its tables and settings'),
                    ])
                    ->modalSubmitActionLabel(__('Uninstall'))
                    ->action(function (array $record, array $data, Action $action) {
                        abort_unless(static::canAccess(), 403);

                        try {
                            app(PluginInstaller::class)->uninstall($this->plugin($record), (bool) ($data['delete_data'] ?? false));
                        } catch (PluginInstallException $e) {
                            Notification::make()->danger()->persistent()->title(__('The plugin was not uninstalled'))->body($e->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        $this->flushCachedTableRecords();
                        Notification::make()->success()->title(__(':plugin uninstalled', ['plugin' => $record['name']]))->send();
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([$this->bulkSwitch(true), $this->bulkSwitch(false)])]);
    }

    /** Enable or Disable on each picked plugin it applies to, as its own button would; the others are named. */
    private function bulkSwitch(bool $enable): BulkAction
    {
        $skip = function (array $record) use ($enable): ?string {
            $state = $this->plugins()->state($this->plugin($record));

            return match (true) {
                $enable && $state === PluginState::Enabled => __('already enabled'),
                $enable && $state !== PluginState::Disabled => $state->getLabel(),
                ! $enable && $state !== PluginState::Enabled => __('not enabled'),
                ! $enable && $record['id'] === 'demo' => __('the demo needs it'),
                default => null,
            };
        };

        return BulkAction::make($enable ? 'enableMany' : 'disableMany')
            ->label($enable ? 'Enable' : 'Disable')
            ->icon($enable ? Heroicon::OutlinedPlay : Heroicon::OutlinedPause)
            ->color($enable ? 'success' : 'warning')
            ->requiresConfirmation()
            ->modalHeading($enable ? __('Enable plugins') : __('Disable plugins'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).($enable ? '' : ' '
                .__('Their servers, accounts and settings stay. Nothing new can use them, and automation that reaches them stops with an error in the activity log until you enable them again.')))
            ->modalSubmitActionLabel($enable ? __('Enable them') : __('Disable them'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($enable, $skip) {
                BulkOutcome::run(
                    $records,
                    $skip,
                    fn (array $record) => $enable ? $this->plugins()->enable($this->plugin($record)) : $this->plugins()->disable($this->plugin($record)),
                    fn (array $record) => $record['name'],
                )->notify($enable ? ':count plugin enabled|:count plugins enabled' : ':count plugin disabled|:count plugins disabled');

                $this->flushCachedTableRecords();
            });
    }

    /** The order of the types on the page; a plugin with another category sorts last. */
    private const CATEGORIES = ['Servers', 'Registrars', 'Monitoring', 'Payments', 'Integrations', 'AI', 'Themes'];

    /**
     * The table rows, narrowed by the Type / Status / Source filters and the search (name, description, type or folder).
     * "Disabled" is every plugin that is not running: switched off, off in the demo or needing a newer GaiaCP.
     *
     * @param  array<string, array<string, mixed>>  $filters
     * @return array<string, array<string, mixed>>
     */
    private function rows(array $filters, ?string $search): array
    {
        $types = array_filter((array) ($filters['type']['values'] ?? []));
        $status = $filters['status']['value'] ?? null;
        $source = $filters['source']['value'] ?? null;
        $search = Str::lower(trim((string) $search));

        return $this->plugins()->all()
            ->filter(fn (Plugin $plugin) => $types === [] || in_array($plugin->category(), $types, true))
            ->filter(fn (Plugin $plugin) => blank($status) || ($status === 'enabled') === ($this->plugins()->state($plugin) === PluginState::Enabled))
            ->filter(fn (Plugin $plugin) => blank($source) || ($source === 'bundled') === $this->plugins()->bundled($plugin))
            ->filter(fn (Plugin $plugin) => $search === '' || Str::contains(
                Str::lower(implode(' ', [$plugin->name(), $plugin->description(), $plugin->id(), self::categoryLabel($plugin->category())])),
                $search))
            ->map(fn (Plugin $plugin) => [
                'id' => $plugin->id(),
                'name' => $plugin->name(),
                'description' => $plugin->description(),
                'category' => self::categoryLabel($plugin->category()),
                'version' => $plugin->version(),
                'version_note' => $this->versionNote($plugin),
                'state' => $this->plugins()->state($plugin),
                'bundled' => $this->plugins()->bundled($plugin),
            ])
            ->sortBy(function (array $row, string $id) {
                $position = array_search($this->plugins()->find($id)->category(), self::CATEGORIES, true);

                return $position === false ? count(self::CATEGORIES) : $position;
            })
            ->all();
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'Servers' => __('Server panels'),
            'Registrars' => __('Domain registrars'),
            'Monitoring' => __('Monitoring'),
            'Payments' => __('Payment methods'),
            'AI' => __('AI assistant'),
            'Themes' => __('Themes'),
            default => __('Integrations'),
        };
    }

    /** Under the plugin's name: its version badge, the version note next to it, then what it does. */
    private static function versionAndDescription(array $record): HtmlString
    {
        $badge = Blade::render('<x-filament::badge :color="$color" icon="heroicon-o-tag" size="sm">{{ $label }}</x-filament::badge>', [
            'color' => $record['state'] === PluginState::Incompatible ? 'danger' : 'primary',
            'label' => 'v'.$record['version'],
        ]);
        $source = Blade::render('<x-filament::badge color="gray" size="sm">{{ $label }}</x-filament::badge>', [
            'label' => $record['bundled'] ? __('Bundled') : __('Uploaded'),
        ]);
        $note = filled($record['version_note']) ? '<span>'.e($record['version_note']).'</span>' : '';

        return new HtmlString('<span style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin: 0.25rem 0 0.375rem">'
            .$badge.$source.$note.'</span>'.e($record['description']));
    }

    /** Under the version: what it needs when it can't run here, else since when it is installed or from what it was updated. */
    private function versionNote(Plugin $plugin): ?string
    {
        if (! $this->plugins()->compatible($plugin)) {
            return __('Needs GaiaCP :version or later', ['version' => $plugin->requires()]);
        }

        if ($update = $this->catalogueUpdates()[$plugin->id()] ?? null) {
            return __(':version is in the plugin catalogue', ['version' => $update]);
        }

        if ($waiting = $this->catalogueWaiting()[$plugin->id()] ?? null) {
            return __(':version is in the plugin catalogue but needs :needs', ['version' => $waiting->version, 'needs' => $waiting->missing()]);
        }

        $installed = $this->plugins()->installed($plugin);
        $date = $installed?->changedAt?->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat());

        return match (true) {
            $date === null => null,
            filled($installed->previous) && version_compare($installed->previous, $installed->version, '>') => __('Rolled back from :version on :date', ['version' => $installed->previous, 'date' => $date]),
            filled($installed->previous) => __('Updated from :version on :date', ['version' => $installed->previous, 'date' => $date]),
            default => __('Installed on :date', ['date' => $date]),
        };
    }

    /** The changelog without its "# Changelog" title and intro, from the first version on. */
    private static function withoutTitle(string $changelog): string
    {
        $start = strpos($changelog, "\n## ");

        return $start === false ? $changelog : substr($changelog, $start + 1);
    }

    /** @var array<string, string>|null this request's catalogueUpdates() */
    private ?array $catalogueUpdates = null;

    /** @return array<string, string> plugin id => the newer version in the last catalogue read (no request made here) */
    private function catalogueUpdates(): array
    {
        if (! Catalogue::enabled() || ! ($catalogue = app(Catalogue::class)->stored())) {
            return $this->catalogueUpdates = [];
        }

        return $this->catalogueUpdates ??= collect(app(CatalogueInstaller::class)->updates($catalogue))
            ->mapWithKeys(fn (array $update) => [$update['plugin']->id() => $update['version']->version])
            ->all();
    }

    /** @var array<string, ListingVersion>|null this request's catalogueWaiting() */
    private ?array $catalogueWaiting = null;

    /** @return array<string, ListingVersion> plugin id => its newest version in the last catalogue read, which needs a newer GaiaCP or PHP */
    private function catalogueWaiting(): array
    {
        if (! Catalogue::enabled() || ! ($catalogue = app(Catalogue::class)->stored())) {
            return $this->catalogueWaiting = [];
        }

        return $this->catalogueWaiting ??= collect(app(CatalogueInstaller::class)->waiting($catalogue))
            ->mapWithKeys(fn (array $waiting) => [$waiting['plugin']->id() => $waiting['version']])
            ->all();
    }

    /** The version Roll back would put back, or null. */
    private function previousVersion(array $record): ?string
    {
        return app(PluginInstaller::class)->previousVersion($this->plugin($record))['version'] ?? null;
    }

    private function plugin(array $record): Plugin
    {
        return $this->plugins()->find($record['id']);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}

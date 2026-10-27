<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Plugins\Catalogue\Catalogue;
use App\Plugins\Catalogue\CatalogueInstaller;
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
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
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
 * bundled ones, can be uninstalled once disabled.
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
            ->records(fn () => $this->plugins()->all()
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
                ->sortBy(fn (array $row, string $id) => array_search($this->plugins()->find($id)->category(), ['Servers', 'Registrars', 'Monitoring', 'Payments', 'Integrations', 'AI', 'Themes'], true))
                ->all())
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
            ]);
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

        $installed = $this->plugins()->installed($plugin);
        $date = $installed?->changedAt?->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat());

        return match (true) {
            $date === null => null,
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

    private function plugin(array $record): Plugin
    {
        return $this->plugins()->find($record['id']);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}

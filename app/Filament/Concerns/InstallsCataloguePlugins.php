<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\Plugins;
use App\Plugins\Catalogue\Catalogue;
use App\Plugins\Catalogue\CatalogueData;
use App\Plugins\Catalogue\CatalogueException;
use App\Plugins\Catalogue\CatalogueInstaller;
use App\Plugins\Catalogue\Listing;
use App\Plugins\Catalogue\Tags;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use App\Support\Translations;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * What the plugin catalogue's list (PluginCatalogue) and a plugin's own page (PluginCatalogueEntry) share: the stored
 * catalogue, a plugin's status here, and Install / Update. The page using it says which plugin an action is about
 * through cataloguePlugin(), given the table row when there is one.
 */
trait InstallsCataloguePlugins
{
    /** @param  array<string, mixed>|null  $record  the table row, on the list */
    abstract protected function cataloguePlugin(?array $record): Listing;

    /** Called after a plugin was installed or updated, to show its new status. */
    abstract protected function afterCatalogueInstall(): void;

    protected function catalogue(): Catalogue
    {
        return app(Catalogue::class);
    }

    protected function installer(): CatalogueInstaller
    {
        return app(CatalogueInstaller::class);
    }

    protected function data(): ?CatalogueData
    {
        return Catalogue::enabled() ? $this->catalogue()->stored() : null;
    }

    /**
     * A plugin as the pages show it.
     *
     * @return array<string, mixed>
     */
    protected function row(Listing $listing): array
    {
        $installed = $this->installer()->installed($listing);
        $best = $listing->best();
        $update = $this->installer()->updateFor($listing);

        [$key, $status] = match (true) {
            $installed !== null && app(PluginManager::class)->bundled($installed) => ['bundled', __('Bundled')],
            $update !== null => ['update', __('Update to :version', ['version' => $update->version])],
            $installed !== null && ($waiting = $this->installer()->waitingFor($listing)) !== null => ['installed', __('Installed; :version needs :needs', ['version' => $waiting->version, 'needs' => $waiting->missing()])],
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
            'tags' => array_map(Tags::label(...), $listing->tags),
            'author' => $listing->author,
            'version' => ($best ?? $listing->latest())->version,
            'installed' => $installed?->version(),
            'status_key' => $key,
            'status' => $status,
        ];
    }

    public static function statusColor(string $key): string
    {
        return match ($key) {
            'installed' => 'success',
            'update' => 'warning',
            'incompatible' => 'danger',
            default => 'gray',
        };
    }

    protected function communityNote(): string
    {
        return __('A community plugin: written by someone else and reviewed before it was listed. Reviewing is not a guarantee, so install it only if you trust its author.');
    }

    /** Install ($name 'install') or Update ($name 'update') the plugin, after the admin's password. */
    protected function installAction(string $name): Action
    {
        return Action::make($name)
            // "Update" alone reads as "Αλλαγή" (edit) in Greek; the version makes it an update in every language
            ->label(fn (?array $record = null) => $name === 'update'
                ? __('Update to :version', ['version' => $this->row($this->cataloguePlugin($record))['version']])
                : __('Install'))
            ->icon($name === 'update' ? 'heroicon-o-arrow-path' : 'heroicon-o-arrow-down-tray')
            ->color($name === 'update' ? 'warning' : 'primary')
            ->visible(fn (?array $record = null) => PluginInstaller::uploadsAllowed()
                && ($record['status_key'] ?? $this->row($this->cataloguePlugin($record))['status_key']) === ($name === 'update' ? 'update' : 'available'))
            ->modalHeading(function (?array $record = null) use ($name) {
                $row = $this->row($this->cataloguePlugin($record));

                return $name === 'update'
                    ? __('Update :plugin to :version?', ['plugin' => $row['name'], 'version' => $row['version']])
                    : __('Install :plugin :version?', ['plugin' => $row['name'], 'version' => $row['version']]);
            })
            ->modalDescription(fn (?array $record = null) => collect([
                $this->cataloguePlugin($record)->official ? null : $this->communityNote(),
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
            ->action(function (Action $action, ?array $record = null) {
                abort_unless(static::canAccess() && PluginInstaller::uploadsAllowed(), 403); // installs code: checked again on the request that does it

                try {
                    $plugin = $this->installer()->install($this->cataloguePlugin($record));
                } catch (CatalogueException|PluginInstallException $e) {
                    Notification::make()->danger()->persistent()->title(__('The plugin was not installed'))->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                $this->afterCatalogueInstall();
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
}

<?php

namespace App\Filament\Clusters\Settings;

use App\Filament\Pages\Plugins;
use App\Plugins\Plugin;
use App\Plugins\PluginManager;
use App\Plugins\PluginState;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;

/**
 * An On switch at the top of a plugin's settings page: the same state as its Enable / Disable under Settings →
 * Plugins, applied at once. The page stays open while the plugin is off, so it can be switched back on here.
 * Only admins who may open Settings → Plugins (super admins) can flip it.
 */
trait PluginSwitch
{
    public bool $pluginEnabled = false;

    /** The plugin's id(). */
    abstract public static function pluginId(): string;

    public function mountPluginSwitch(): void
    {
        $this->pluginEnabled = app(PluginManager::class)->enabled(static::pluginId());
    }

    /** @return list<Section> */
    protected function beforeForm(): array
    {
        $plugins = app(PluginManager::class);
        $plugin = $plugins->find(static::pluginId());

        return [
            Section::make()
                ->schema([
                    Toggle::make('pluginEnabled')
                        ->label(__('On'))
                        ->helperText(fn () => match (true) {
                            $plugins->state($plugin) === PluginState::Incompatible => __('Needs GaiaCP :version or later', ['version' => $plugin->requires()]),
                            $this->pluginEnabled => __('In use. Turning it off keeps these settings; nothing new uses it until it is on again.'),
                            default => __('Off: not used anywhere. These settings are kept for when it is on again.'),
                        })
                        ->disabled(fn () => ! Plugins::canAccess() || $plugins->state($plugin) === PluginState::Incompatible)
                        ->live()
                        ->afterStateUpdated(fn (bool $state) => $this->switchPlugin($plugin, $state)),
                ]),
        ];
    }

    private function switchPlugin(Plugin $plugin, bool $on): void
    {
        abort_unless(Plugins::canAccess(), 403);

        $plugins = app(PluginManager::class);
        $on ? $plugins->enable($plugin) : $plugins->disable($plugin);
        $this->pluginEnabled = $plugins->enabled($plugin);

        Notification::make()->success()
            ->title($this->pluginEnabled ? __(':plugin enabled', ['plugin' => $plugin->name()]) : __(':plugin disabled', ['plugin' => $plugin->name()]))
            ->send();
    }
}

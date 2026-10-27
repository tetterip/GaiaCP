<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Providers\AppServiceProvider;
use App\Support\Version;
use App\Updates\Release;
use App\Updates\Updater;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Settings → System → Updates: this install's version against the latest GaiaCP release, and Update, which
 * backs up the database and installs it (App\Updates\Updater). A Git checkout is updated by deploying instead.
 */
class Updates extends Page implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 91;

    protected static ?string $title = 'Updates';

    /** The latest release as an array (Livewire keeps it between requests), null when none is published. */
    public ?array $release = null;

    public ?string $error = null;

    public static function settingsDescription(): string
    {
        return __('This version, the latest release and what changed; update with a backup first.');
    }

    public function mount(): void
    {
        $this->check(fresh: false);
    }

    private function updater(): Updater
    {
        return app(Updater::class);
    }

    private function check(bool $fresh): void
    {
        try {
            $this->release = $this->updater()->latest($fresh)?->toArray();
            $this->error = null;
        } catch (Throwable $e) {
            $this->release = null;
            $this->error = $e->getMessage();
        }
    }

    private function latest(): ?Release
    {
        return $this->release ? Release::fromArray($this->release) : null;
    }

    private function updateAvailable(): bool
    {
        return $this->latest() !== null && $this->updater()->isNewer($this->latest());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkNow')
                ->label('Check now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->check(fresh: true)),
            Action::make('update')
                ->label(fn () => __('Update to :version', ['version' => $this->latest()?->version]))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn () => $this->updateAvailable() && ! $this->updater()->isGitCheckout())
                ->requiresConfirmation()
                ->modalHeading(fn () => __('Update to GaiaCP :version?', ['version' => $this->latest()?->version]))
                ->modalDescription(__('The database is backed up first. The panel is then in maintenance mode for a minute or so while the new code goes in and the database is updated. If a step fails, this version is put back.'))
                ->modalSubmitActionLabel(__('Back up and update'))
                ->action(function () {
                    $release = $this->latest();

                    try {
                        $this->updater()->update($release);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->persistent()->title(__('The update failed'))->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Updated to GaiaCP :version', ['version' => $release->version]))->send();
                    // A fresh page, rendered by the new code only
                    $this->redirect(static::getUrl());
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $current = Version::current();
        $release = $this->latest();

        return $schema->components(array_values(array_filter([
            match (true) {
                $this->error !== null => Callout::make(__('Could not check for updates'))->description($this->error)->danger(),
                $release === null => Callout::make(__('No release has been published yet'))->description(__('This is GaiaCP :version.', ['version' => $current]))->info(),
                $this->updateAvailable() => Callout::make(__('GaiaCP :version is available', ['version' => $release->version]))
                    ->description(__('This is :current. Released on :date.', ['current' => $current, 'date' => $release->publishedAt?->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat()) ?? '-']))
                    ->warning(),
                default => Callout::make(__('GaiaCP :version is up to date', ['version' => $current]))->success(),
            },
            $this->updater()->isGitCheckout()
                ? Callout::make(__('This copy runs from a Git checkout'))
                    ->description(__('It is updated by deploying from Git (git pull, composer install, migrate), so there is no Update button here.'))
                    ->info()
                : null,
            $release !== null && filled($release->notes)
                ? Section::make(__('What\'s new in :version', ['version' => $release->version]))
                    ->schema([
                        Html::make(new HtmlString('<div class="fi-prose">'.Str::markdown($release->notes, ['html_input' => 'strip', 'allow_unsafe_links' => false]).'</div>')),
                        Text::make(new HtmlString('<a href="'.e($release->pageUrl).'" target="_blank" rel="noopener">'.e(__('Release page on GitHub')).'</a>')),
                    ])
                : null,
            Section::make(__('How updating works'))
                ->schema([
                    Text::make(__('Update backs up the database first (copied to FTP and email as Settings → Backups says), then downloads the release and checks its checksum. The panel goes into maintenance mode while the new code replaces the old one: .env, storage (uploads, backups, logs), the SQLite database and plugins of your own stay. Then the database is updated and the panel comes back. If a step fails, this version is put back and the backup is there to restore.')),
                    Text::make(__('From SSH: php artisan gaiacp:update')),
                ])
                ->collapsible()
                ->collapsed(),
        ])));
    }
}

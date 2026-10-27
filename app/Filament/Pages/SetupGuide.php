<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Support\SetupGuide as Guide;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings → General → Setup guide (/admin/settings/setup): the first steps of a new install, each opening the page
 * where it is set (App\Support\SetupGuide). Super admins only, since most of those pages are theirs. The installer's
 * last step and the dashboard's card lead here.
 */
class SetupGuide extends Page implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'General';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Setup guide';

    protected static ?string $slug = 'setup';

    protected string $view = 'filament.setup-guide.page';

    public static function settingsDescription(): string
    {
        return __('The first steps of a new install, one by one, with what is done so far.');
    }

    public function getSubheading(): ?string
    {
        ['done' => $done, 'total' => $total] = Guide::progress();

        return $done === $total
            ? __('All set. Everything here can still be changed under Settings.')
            : __(':done of :total steps done. Each one opens the page where it is set; skip the ones you don\'t need.', ['done' => $done, 'total' => $total]);
    }

    public function mark(string $key, ?string $status): void
    {
        Guide::mark($key, $status);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('dashboard')
                ->label(fn () => Guide::state()['hidden'] ? __('Show on the dashboard') : __('Hide from the dashboard'))
                ->icon(fn () => Guide::state()['hidden'] ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash)
                ->color('gray')
                ->action(fn () => Guide::hide(! Guide::state()['hidden'])),
        ];
    }
}

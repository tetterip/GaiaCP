<?php

namespace App\Filament;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Services\ServiceResource;

/**
 * Admin keyboard shortcuts, shown by "?" (resources/views/filament/keyboard-shortcuts.blade.php, added at the end of
 * every admin page). Two-key sequences like Gmail: "g" then a letter goes to a page, "n" then a letter opens a new
 * record. Ctrl/⌘+K and "/" focus the global search.
 */
class KeyboardShortcuts
{
    /**
     * Sequences by group: [keys => [label, url]].
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    public static function sequences(): array
    {
        $groups = [
            'Go to' => [
                'g d' => [__('Dashboard'), Dashboard::class, fn () => Dashboard::getUrl()],
                'g c' => [__('Clients'), ClientResource::class, fn () => ClientResource::getUrl()],
                'g o' => [__('Orders'), OrderResource::class, fn () => OrderResource::getUrl()],
                'g h' => [__('Hosting'), ServiceResource::class, fn () => ServiceResource::getUrl('index', ['tab' => 'hosting'])],
                'g n' => [__('Domains'), ServiceResource::class, fn () => ServiceResource::getUrl('index', ['tab' => 'domains'])],
                'g x' => [__('Other services'), ServiceResource::class, fn () => ServiceResource::getUrl('index', ['tab' => 'other'])],
                'g i' => [__('Payment notices'), InvoiceResource::class, fn () => InvoiceResource::getUrl()],
                'g p' => [__('Payments'), PaymentResource::class, fn () => PaymentResource::getUrl()],
                'g l' => [__('Activity log'), ActivityLogResource::class, fn () => ActivityLogResource::getUrl()],
                'g r' => [__('Reports'), ReportsCluster::class, fn () => ReportsCluster::getUrl()],
                'g s' => [__('Settings'), SettingsCluster::class, fn () => SettingsCluster::getUrl()],
            ],
            'New' => [
                'n c' => [__('New client'), ClientResource::class, fn () => ClientResource::getUrl('create')],
                'n o' => [__('New order'), OrderResource::class, fn () => OrderResource::getUrl('create')],
                'n s' => [__('New service'), ServiceResource::class, fn () => ServiceResource::getUrl('create')],
            ],
        ];

        // Only the pages this admin can open (Settings → Admin users).
        return collect($groups)
            ->map(fn (array $items) => collect($items)
                ->filter(fn (array $item) => $item[1]::canAccess())
                ->map(fn (array $item) => [$item[0], $item[2]()])
                ->all())
            ->filter()
            ->all();
    }

    /**
     * Single keys handled in the browser, for the help overlay only.
     *
     * @return array<string, string>
     */
    public static function general(): array
    {
        return [
            'Ctrl K / ⌘ K' => __('Search'),
            '/' => __('Search'),
            '?' => __('Show these shortcuts'),
            'Esc' => __('Close this window'),
        ];
    }

    /** keys without spaces ("gc") => url, for the script. */
    public static function urls(): array
    {
        return collect(static::sequences())
            ->flatMap(fn (array $group) => $group)
            ->mapWithKeys(fn (array $item, string $keys) => [str_replace(' ', '', $keys) => $item[1]])
            ->all();
    }
}

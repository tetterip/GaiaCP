<?php

namespace App\Livewire;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Provisioning\AccountUsage;
use App\Provisioning\ProvisioningException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Disk space and this month's bandwidth of a hosting account, used against its limits, on the admin's service page
 * and the client's. Loaded after the page (lazy), from ServiceLifecycle::usage(), which keeps a reading for a while;
 * the admin can read it again at once.
 */
class HostingUsage extends Component
{
    #[Locked]
    public Service $service;

    /** The client area: no Refresh, and a plain message instead of the panel's error. */
    #[Locked]
    public bool $client = false;

    /** An active or suspended hosting account on a server whose panel plugin is on. */
    public static function hasUsage(?Service $service): bool
    {
        return $service !== null
            && $service->type === ServiceType::Hosting
            && in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)
            && $service->server !== null
            && filled($service->username)
            && app(PluginManager::class)->serverModules()->has($service->server->type);
    }

    /** Set by Read again for this request only. */
    protected bool $readNow = false;

    public function placeholder(): string
    {
        return '<div style="padding: .25rem 0; font-size: .875rem; color: var(--gray-500)">'.e(__('Reading usage from the server…')).'</div>';
    }

    public function refreshUsage(): void
    {
        abort_if($this->client, 403);

        $this->readNow = true;
    }

    public function render(): View
    {
        $usage = null;
        $error = null;

        try {
            $usage = app(ServiceLifecycle::class)->usage($this->service, $this->readNow);
        } catch (ProvisioningException $e) {
            $error = $this->client
                ? __('Usage can\'t be shown right now. Please try again later.')
                : __('Could not read usage: :error', ['error' => $e->getMessage()]);
        }

        return view('livewire.hosting-usage', [
            'usage' => $usage,
            'error' => $error,
            'main' => $usage ? [
                $this->item(__('Disk space'), 'heroicon-o-server', $usage->diskUsed, $usage->diskLimit, bytes: true),
                $this->item(__('Bandwidth this month'), 'heroicon-o-arrows-right-left', $usage->bandwidthUsed, $usage->bandwidthLimit, bytes: true),
            ] : [],
            'tiles' => $usage ? collect(AccountUsage::COUNTS)
                ->filter(fn (string $key) => isset($usage->counts[$key]))
                ->map(fn (string $key) => $this->item(self::label($key), self::ICONS[$key], ...$usage->counts[$key], bytes: $key === 'database_disk'))
                ->values()
                ->all() : [],
        ]);
    }

    private const ICONS = [
        'email_accounts' => 'heroicon-o-envelope',
        'databases' => 'heroicon-o-circle-stack',
        'database_disk' => 'heroicon-o-archive-box',
        'sites' => 'heroicon-o-window',
        'addon_domains' => 'heroicon-o-globe-alt',
        'parked_domains' => 'heroicon-o-link',
        'subdomains' => 'heroicon-o-squares-2x2',
    ];

    private static function label(string $key): string
    {
        return match ($key) {
            'email_accounts' => __('Email accounts'),
            'databases' => __('Databases'),
            'database_disk' => __('Database space'),
            'sites' => __('Websites'),
            'addon_domains' => __('Addon domains'),
            'parked_domains' => __('Parked domains'),
            'subdomains' => __('Subdomains'),
        };
    }

    /** @return array{label: string, icon: string, value: string, limit: string, percent: ?int, color: string} */
    private function item(string $label, string $icon, ?int $used, ?int $limit, bool $bytes = false): array
    {
        $format = fn (int $n) => $bytes ? Number::fileSize($n, maxPrecision: 1) : Number::format($n);
        $percent = AccountUsage::percent($used, $limit);

        return [
            'label' => $label,
            'icon' => $icon,
            'value' => $used === null ? '—' : $format($used),
            'limit' => $limit === null ? __('Unlimited') : __('of :limit', ['limit' => $format($limit)]),
            'percent' => $percent,
            'color' => match (true) {
                $percent === null => 'primary',
                $percent >= 95 => 'danger',
                $percent >= 80 => 'warning',
                default => 'primary',
            },
        ];
    }
}

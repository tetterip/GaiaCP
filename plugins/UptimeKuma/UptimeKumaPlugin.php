<?php

namespace Plugins\UptimeKuma;

use App\Monitoring\MonitoringException;
use App\Monitoring\MonitoringModule;
use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Uptime Kuma (self-hosted) as a monitoring service: servers link to its monitors, read from its /metrics page. */
class UptimeKumaPlugin extends Plugin implements MonitoringModule
{
    public function id(): string
    {
        return 'uptime_kuma';
    }

    public function name(): string
    {
        return 'Uptime Kuma';
    }

    public function description(): string
    {
        return __('Shows whether each server is up from its Uptime Kuma monitor, and tells you in the bell when one goes down.');
    }

    public function category(): string
    {
        return 'Monitoring';
    }

    public function settingsPage(): ?string
    {
        return UptimeKumaSettings::class;
    }

    public function pages(): array
    {
        return [UptimeKumaSettings::class];
    }

    public function settingKeys(): array
    {
        return ['uptime_kuma.url', 'uptime_kuma.username', 'uptime_kuma.password', 'uptime_kuma.verify_tls'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['uptime_kuma.password'];
    }

    public function moduleLogs(): array
    {
        return ['uptime_kuma' => 'Uptime Kuma'];
    }

    public function monitoringKey(): string
    {
        return 'uptime_kuma';
    }

    public function monitoringLabel(): string
    {
        return 'Uptime Kuma';
    }

    public function monitoringConfigured(): bool
    {
        return filled(config('gaiacp.uptime_kuma.url'));
    }

    /** The address as typed, without a trailing slash or the /dashboard page it was copied from. */
    public static function baseUrl(): string
    {
        $url = rtrim((string) config('gaiacp.uptime_kuma.url'), '/');

        return (string) preg_replace('#/(dashboard|metrics)(/.*)?$#', '', $url);
    }

    public function monitors(): array
    {
        if (! $this->monitoringConfigured()) {
            throw new MonitoringException(__('Fill in the Uptime Kuma address under Settings → Uptime Kuma.'));
        }

        $password = (string) config('gaiacp.uptime_kuma.password');
        $request = Http::timeout(15)
            ->accept('text/plain')
            ->withOptions(['verify' => (bool) config('gaiacp.uptime_kuma.verify_tls', true)])
            ->withMiddleware(ModuleLogger::middleware('uptime_kuma', 'metrics', [$password]));

        if ($password !== '') {
            $request = $request->withBasicAuth((string) config('gaiacp.uptime_kuma.username'), $password);
        }

        try {
            $response = $request->get(self::baseUrl().'/metrics');
        } catch (ConnectionException $e) {
            throw new MonitoringException(__('Could not reach Uptime Kuma: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new MonitoringException(__('Uptime Kuma refused the API key.'));
        }

        if (! $response->successful()) {
            throw new MonitoringException(__('Uptime Kuma answered with HTTP :status.', ['status' => $response->status()]));
        }

        if (! KumaMetrics::looksLikeKuma($response->body())) {
            throw new MonitoringException(__('No monitors found at :url. Check the address, and that Uptime Kuma has at least one active monitor.', ['url' => self::baseUrl().'/metrics']));
        }

        return KumaMetrics::parse($response->body());
    }
}

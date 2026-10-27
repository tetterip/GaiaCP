<?php

namespace Plugins\HetrixTools;

use App\Monitoring\MonitoringException;
use App\Monitoring\MonitoringModule;
use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** HetrixTools (hosted) as a monitoring service: servers link to its uptime monitors, read from API v3. */
class HetrixToolsPlugin extends Plugin implements MonitoringModule
{
    public const API_URL = 'https://api.hetrixtools.com/v3/uptime-monitors';

    private const PAGE = 100;

    private const MAX_PAGES = 5;

    public function id(): string
    {
        return 'hetrix_tools';
    }

    public function name(): string
    {
        return 'HetrixTools';
    }

    public function description(): string
    {
        return __('Shows whether each server is up from its HetrixTools uptime monitor, and tells you in the bell when one goes down.');
    }

    public function category(): string
    {
        return 'Monitoring';
    }

    public function settingsPage(): ?string
    {
        return HetrixToolsSettings::class;
    }

    public function pages(): array
    {
        return [HetrixToolsSettings::class];
    }

    public function settingKeys(): array
    {
        return ['hetrix_tools.api_token'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['hetrix_tools.api_token'];
    }

    public function moduleLogs(): array
    {
        return ['hetrix_tools' => 'HetrixTools'];
    }

    public function monitoringKey(): string
    {
        return 'hetrix_tools';
    }

    public function monitoringLabel(): string
    {
        return 'HetrixTools';
    }

    public function monitoringConfigured(): bool
    {
        return filled(config('gaiacp.hetrix_tools.api_token'));
    }

    public function monitors(): array
    {
        if (! $this->monitoringConfigured()) {
            throw new MonitoringException(__('Fill in the HetrixTools API token under Settings → HetrixTools.'));
        }

        $token = trim((string) config('gaiacp.hetrix_tools.api_token'));
        $monitors = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->page($token, $page);
            $list = $body['monitors'] ?? $body['data'] ?? (array_is_list($body) ? $body : []);
            $monitors += HetrixMonitors::parse(is_array($list) ? $list : []);

            $pages = (int) (data_get($body, 'meta.pagination.total_pages') ?? data_get($body, 'meta.last_page') ?? data_get($body, 'pagination.total_pages') ?? 0);
            $more = $pages > 0 ? $page < $pages : (filled(data_get($body, 'links.next')) || count($list) >= self::PAGE);
            if (! $more) {
                break;
            }
        }

        return $monitors;
    }

    /** @throws MonitoringException */
    private function page(string $token, int $page): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($token)
                ->withMiddleware(ModuleLogger::middleware('hetrix_tools', 'uptime-monitors', [$token]))
                ->get(self::API_URL, ['page' => $page, 'per_page' => self::PAGE]);
        } catch (ConnectionException $e) {
            throw new MonitoringException(__('Could not reach HetrixTools: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if ($response->status() === 429) {
            throw new MonitoringException(__('HetrixTools asked to slow down (too many requests). It is read again at the next check.'));
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new MonitoringException(__('HetrixTools refused the API token.'));
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            $error = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? '') : '';

            throw new MonitoringException($error !== ''
                ? __('HetrixTools answered: :error', ['error' => $error])
                : __('HetrixTools answered with HTTP :status.', ['status' => $response->status()]));
        }

        return $body;
    }
}

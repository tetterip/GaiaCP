<?php

namespace Plugins\BetterStack;

use App\Monitoring\MonitoringException;
use App\Monitoring\MonitoringModule;
use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Better Stack Uptime (hosted) as a monitoring service: servers link to its monitors, read from API v2. */
class BetterStackPlugin extends Plugin implements MonitoringModule
{
    public const API_URL = 'https://uptime.betterstack.com/api/v2/monitors';

    private const PAGE = 250;

    private const MAX_PAGES = 5;

    public function id(): string
    {
        return 'better_stack';
    }

    public function name(): string
    {
        return 'Better Stack';
    }

    public function description(): string
    {
        return __('Shows whether each server is up from its Better Stack Uptime monitor, and tells you in the bell when one goes down.');
    }

    public function category(): string
    {
        return 'Monitoring';
    }

    public function settingsPage(): ?string
    {
        return BetterStackSettings::class;
    }

    public function pages(): array
    {
        return [BetterStackSettings::class];
    }

    public function settingKeys(): array
    {
        return ['better_stack.api_token'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['better_stack.api_token'];
    }

    public function moduleLogs(): array
    {
        return ['better_stack' => 'Better Stack'];
    }

    public function monitoringKey(): string
    {
        return 'better_stack';
    }

    public function monitoringLabel(): string
    {
        return 'Better Stack';
    }

    public function monitoringConfigured(): bool
    {
        return filled(config('gaiacp.better_stack.api_token'));
    }

    public function monitors(): array
    {
        if (! $this->monitoringConfigured()) {
            throw new MonitoringException(__('Fill in the Better Stack API token under Settings → Better Stack.'));
        }

        $token = trim((string) config('gaiacp.better_stack.api_token'));
        $monitors = [];
        $url = self::API_URL.'?per_page='.self::PAGE;

        // Each page names the next one (pagination.next), until it is null.
        for ($page = 1; $page <= self::MAX_PAGES && is_string($url) && $url !== ''; $page++) {
            $body = $this->page($token, $url);
            $monitors += BetterStackMonitors::parse(is_array($body['data'] ?? null) ? $body['data'] : []);
            $url = data_get($body, 'pagination.next');

            // Only follow it on Better Stack's own address: the token goes with it.
            if (is_string($url) && ! str_starts_with($url, 'https://uptime.betterstack.com/')) {
                break;
            }
        }

        return $monitors;
    }

    /** @throws MonitoringException */
    private function page(string $token, string $url): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($token)
                ->withMiddleware(ModuleLogger::middleware('better_stack', 'monitors', [$token]))
                ->get($url);
        } catch (ConnectionException $e) {
            throw new MonitoringException(__('Could not reach Better Stack: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if ($response->status() === 429) {
            throw new MonitoringException(__('Better Stack asked to slow down (too many requests). It is read again at the next check.'));
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new MonitoringException(__('Better Stack refused the API token.'));
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            $error = is_array($body) ? (string) (data_get($body, 'errors.0.detail') ?? data_get($body, 'errors') ?? $body['message'] ?? '') : '';

            throw new MonitoringException($error !== '' && $error !== 'Array'
                ? __('Better Stack answered: :error', ['error' => $error])
                : __('Better Stack answered with HTTP :status.', ['status' => $response->status()]));
        }

        return $body;
    }
}

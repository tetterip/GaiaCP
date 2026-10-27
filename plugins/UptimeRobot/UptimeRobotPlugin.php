<?php

namespace Plugins\UptimeRobot;

use App\Monitoring\MonitoringException;
use App\Monitoring\MonitoringModule;
use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** UptimeRobot (hosted) as a monitoring service: servers link to its monitors, read with getMonitors (API v2). */
class UptimeRobotPlugin extends Plugin implements MonitoringModule
{
    public const API_URL = 'https://api.uptimerobot.com/v2/getMonitors';

    /** getMonitors gives at most 50 a page; the Free plan's 10 calls a minute allow a few pages. */
    private const PAGE = 50;

    private const MAX_PAGES = 5;

    public function id(): string
    {
        return 'uptime_robot';
    }

    public function name(): string
    {
        return 'UptimeRobot';
    }

    public function description(): string
    {
        return __('Shows whether each server is up from its UptimeRobot monitor, and tells you in the bell when one goes down.');
    }

    public function category(): string
    {
        return 'Monitoring';
    }

    public function settingsPage(): ?string
    {
        return UptimeRobotSettings::class;
    }

    public function pages(): array
    {
        return [UptimeRobotSettings::class];
    }

    public function settingKeys(): array
    {
        return ['uptime_robot.api_key'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['uptime_robot.api_key'];
    }

    public function moduleLogs(): array
    {
        return ['uptime_robot' => 'UptimeRobot'];
    }

    public function monitoringKey(): string
    {
        return 'uptime_robot';
    }

    public function monitoringLabel(): string
    {
        return 'UptimeRobot';
    }

    public function monitoringConfigured(): bool
    {
        return filled(config('gaiacp.uptime_robot.api_key'));
    }

    public function monitors(): array
    {
        if (! $this->monitoringConfigured()) {
            throw new MonitoringException(__('Fill in the UptimeRobot API key under Settings → UptimeRobot.'));
        }

        $key = trim((string) config('gaiacp.uptime_robot.api_key'));
        $monitors = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->page($key, $page * self::PAGE);
            $monitors += RobotMonitors::parse($body['monitors'] ?? []);

            $total = (int) data_get($body, 'pagination.total', 0);
            if (count($body['monitors'] ?? []) < self::PAGE || ($page + 1) * self::PAGE >= $total) {
                break;
            }
        }

        return $monitors;
    }

    /** @throws MonitoringException */
    private function page(string $key, int $offset): array
    {
        try {
            $response = Http::timeout(15)
                ->asForm()
                ->acceptJson()
                ->withMiddleware(ModuleLogger::middleware('uptime_robot', 'getMonitors', [$key]))
                ->post(self::API_URL, [
                    'api_key' => $key,
                    'format' => 'json',
                    'custom_uptime_ratios' => implode('-', array_keys(RobotMonitors::WINDOWS)),
                    'response_times' => 1,
                    'response_times_limit' => 1,
                    'offset' => $offset,
                    'limit' => self::PAGE,
                ]);
        } catch (ConnectionException $e) {
            throw new MonitoringException(__('Could not reach UptimeRobot: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if ($response->status() === 429) {
            throw new MonitoringException(__('UptimeRobot asked to slow down (too many requests). It is read again at the next check.'));
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['stat'])) {
            throw new MonitoringException(__('UptimeRobot answered with HTTP :status.', ['status' => $response->status()]));
        }

        if ($body['stat'] !== 'ok') {
            $error = (string) data_get($body, 'error.message', data_get($body, 'error.type', ''));

            throw new MonitoringException(str_contains(strtolower($error), 'api_key')
                ? __('UptimeRobot refused the API key.')
                : __('UptimeRobot answered: :error', ['error' => $error ?: 'fail']));
        }

        return $body;
    }
}

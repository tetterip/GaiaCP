<?php

namespace Plugins\Demo;

use App\Models\Server;
use App\Monitoring\Monitor;
use App\Monitoring\MonitoringModule;
use App\Monitoring\MonitorStatus;
use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\RegistrarModule;
use App\Provisioning\ServerModule;

/**
 * The public demo's server panel, registrar and monitoring service (App\Support\Demo): servers of type "demo", the
 * registrar module "demo" and the monitoring key "demo". Nothing here connects anywhere: the panel answers from the
 * services GaiaCP stores, the registrar from the domains, and every demo server is up. Only loaded in the demo
 * (PluginManager), where it is always on.
 */
class DemoPlugin extends Plugin implements MonitoringModule, RegistrarModule, ServerModule
{
    public function id(): string
    {
        return 'demo';
    }

    public function name(): string
    {
        return __('Demo');
    }

    public function description(): string
    {
        return __('Made-up servers, registrar and monitoring for this demo. They never connect anywhere.');
    }

    public function category(): string
    {
        return 'Servers';
    }

    public function key(): string
    {
        return 'demo';
    }

    public function label(): string
    {
        return __('Demo panel');
    }

    public function defaultPort(): int
    {
        return 443;
    }

    public function clientPort(): int
    {
        return 443;
    }

    public function provisioner(Server $server): HostingProvisioner
    {
        return new DemoPanel($server);
    }

    public function usernameRequired(): bool
    {
        return false;
    }

    public function usernameHelp(): string
    {
        return __('Not used by the demo panel.');
    }

    public function tokenHelp(): string
    {
        return __('Not used by the demo panel.');
    }

    public function serverFields(): array
    {
        return [];
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new DemoRegistrar;
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function monitoringKey(): string
    {
        return 'demo';
    }

    public function monitoringLabel(): string
    {
        return __('Demo monitoring');
    }

    public function monitoringConfigured(): bool
    {
        return true;
    }

    /** One monitor per demo server (its id as the ref), always up, with a response time that moves a little. */
    public function monitors(): array
    {
        return Server::query()->where('type', 'demo')->get()
            ->mapWithKeys(fn (Server $server) => [(string) $server->id => new Monitor(
                ref: (string) $server->id,
                name: $server->name,
                status: MonitorStatus::Up,
                host: $server->hostname,
                responseMs: 80 + random_int(0, 60),
                uptime: ['1d' => 1.0, '30d' => 0.9995, '365d' => 0.9991],
            )])
            ->all();
    }
}

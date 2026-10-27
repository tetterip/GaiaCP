<?php

namespace App\Monitoring;

/**
 * An external uptime monitoring service (a plugin, Plugin::monitoringModules()), e.g. Uptime Kuma. A server picks
 * one of its monitors (servers.monitor_module / monitor_ref) and ServerMonitoring copies that monitor's status.
 */
interface MonitoringModule
{
    /** Stable key, stored in servers.monitor_module. */
    public function monitoringKey(): string;

    public function monitoringLabel(): string;

    /** Whether its settings are filled in, so it can be asked. */
    public function monitoringConfigured(): bool;

    /**
     * Every monitor with its current status, read in one call.
     *
     * @return array<string, Monitor> ref => monitor
     *
     * @throws MonitoringException
     */
    public function monitors(): array;
}

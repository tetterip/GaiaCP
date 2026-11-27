<?php

namespace Tests;

use App\Models\Registrar;
use App\Plugins\PluginManager;
use Tests\Support\TestRegistrar\TestRegistrarPlugin;

/**
 * A registrar account for the domain tests (Tests\Support\TestRegistrar): keeps domains (expiry, nameservers, EPP
 * code, contacts) in memory. $registrarCalls records [action, params] pairs; a test fails an action with
 * $registrarFailures[action] = [code => message].
 */
trait FakesRegistrar
{
    /** @var array<string, array<string, mixed>> */
    public array $registrarDomains = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $registrarCalls = [];

    /** @var array<string, array<int|string, string>> */
    public array $registrarFailures = [];

    /** A registration comes back with the zero date easy.gr gives a .gr the registry has not confirmed yet. */
    public bool $registrarNoExpiryYet = false;

    /** @var list<array<string, mixed>> {tld, min_period, min_buy, min_ren, transfer, redemption} */
    public array $registrarTldPrices = [];

    /** The registrar account the fake answers for; sells .gr, .com and .com.gr. */
    protected Registrar $testRegistrar;

    protected function fakeRegistrar(): void
    {
        $plugins = app(PluginManager::class);
        $plugins->register($plugin = new TestRegistrarPlugin($this));
        $plugins->enable($plugin);

        $this->testRegistrar = Registrar::factory()->withTlds()->create(['name' => 'Test registrar', 'module' => 'test']);
    }

    /** @return list<string> the actions called, in order (Tests\Support\TestRegistrar\TestRegistrar::SYNC...) */
    protected function registrarActions(): array
    {
        return array_column($this->registrarCalls, 0);
    }
}

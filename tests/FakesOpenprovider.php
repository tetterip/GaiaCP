<?php

namespace Tests;

use App\Models\Registrar;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Openprovider\OpenproviderClient;

/**
 * A tiny in-memory Openprovider account: keeps domains, customer handles and private nameservers and answers the REST
 * calls GaiaCP uses. $openproviderCalls records ["METHOD path", body] pairs, the login left out.
 */
trait FakesOpenprovider
{
    /** @var array<string, array<string, mixed>> by domain name */
    protected array $openproviderDomains = [];

    /** @var array<string, array<string, mixed>> by handle */
    protected array $openproviderCustomers = [];

    /** @var array<string, string> host => IP */
    protected array $openproviderHosts = [];

    /** @var array<string, array<string, float>> tld => [create_price, renew_price, transfer_price] */
    protected array $openproviderPrices = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    protected array $openproviderCalls = [];

    protected int $openproviderLogins = 0;

    protected Registrar $openproviderRegistrar;

    protected function fakeOpenprovider(): void
    {
        $this->openproviderRegistrar = Registrar::factory()->withTlds(['.com', '.net'])->create([
            'name' => 'Openprovider', 'module' => 'openprovider', 'credentials' => ['username' => 'reseller', 'password' => 'secret'],
        ]);

        Http::fake(function (Request $request) {
            $url = strtok($request->url(), '?');
            $base = str_starts_with($url, OpenproviderClient::SANDBOX_URL) ? OpenproviderClient::SANDBOX_URL : OpenproviderClient::LIVE_URL;

            if (! str_starts_with($url, $base.'/')) {
                return Http::response('Not found', 404);
            }

            $path = substr($url, strlen($base) + 1);
            $method = $request->method();
            $body = $method === 'GET' ? $request->data() : (json_decode($request->body(), true) ?: []);

            if ($path === 'auth/login') {
                $this->openproviderLogins++;

                return $body['username'] === 'reseller' && $body['password'] === 'secret'
                    ? $this->openproviderReply(['token' => 'tok-123', 'reseller_id' => 1])
                    : $this->openproviderError(196, 'Authentication/Authorization Failed', 401);
            }

            if ($request->header('Authorization')[0] !== 'Bearer tok-123') {
                return $this->openproviderError(196, 'Authentication/Authorization Failed', 401);
            }

            $this->openproviderCalls[] = ["{$method} {$path}", $body];
            $byId = fn (int $id) => array_key_first(array_filter($this->openproviderDomains, fn (array $d) => $d['id'] === $id));
            $record = fn (string $name) => ['id' => $this->openproviderDomains[$name]['id'], 'domain' => ['name' => strtok($name, '.'), 'extension' => substr($name, strpos($name, '.') + 1)]]
                + $this->openproviderDomains[$name];

            if ($method === 'POST' && $path === 'domains/check') {
                return $this->openproviderReply(['results' => array_map(fn (array $d) => [
                    'domain' => $d['name'].'.'.$d['extension'],
                    'status' => isset($this->openproviderDomains[$d['name'].'.'.$d['extension']]) ? 'active' : 'free',
                    'is_premium' => $d['name'] === 'premium',
                ], $body['domains'])]);
            }

            if ($method === 'GET' && $path === 'domains') {
                $name = $body['full_name'];

                return $this->openproviderReply(['results' => isset($this->openproviderDomains[$name]) ? [$record($name)] : [], 'total' => (int) isset($this->openproviderDomains[$name])]);
            }

            if ($method === 'POST' && in_array($path, ['domains', 'domains/transfer'], true)) {
                $name = $body['domain']['name'].'.'.$body['domain']['extension'];

                if ($path === 'domains') {
                    $this->openproviderDomains[$name] = [
                        'id' => count($this->openproviderDomains) + 1,
                        'expiration_date' => now()->addYears($body['period'])->format('Y-m-d H:i:s'),
                        'name_servers' => $body['name_servers'] ?? [['name' => 'ns1.openprovider.nl', 'seq_nr' => 1]],
                        'owner_handle' => $body['owner_handle'], 'admin_handle' => $body['admin_handle'], 'tech_handle' => $body['tech_handle'], 'billing_handle' => $body['billing_handle'],
                    ];
                }

                return $this->openproviderReply(['id' => 1, 'status' => $path === 'domains' ? 'ACT' : 'REQ']);
            }

            if (preg_match('#^domains/(\d+)/authcode/reset$#', $path, $m)) {
                return $this->openproviderReply(['auth_code' => 'Op#New7']);
            }

            if (preg_match('#^domains/(\d+)(/renew)?$#', $path, $m)) {
                $name = $byId((int) $m[1]);

                if ($name === null) {
                    return $this->openproviderError(320, 'Domain not found', 404);
                }

                if (($m[2] ?? '') === '/renew') {
                    $this->openproviderDomains[$name]['expiration_date'] = now()->parse($this->openproviderDomains[$name]['expiration_date'])->addYears($body['period'])->format('Y-m-d H:i:s');

                    return $this->openproviderReply(['status' => 'ACT']);
                }

                if ($method === 'GET' && ($m[2] ?? '') === '') {
                    return $this->openproviderReply($record($name));
                }

                unset($body['domain']);
                $this->openproviderDomains[$name] = [...$this->openproviderDomains[$name], ...$body];

                return $this->openproviderReply(['status' => 'ACT']);
            }

            if ($method === 'POST' && $path === 'customers') {
                $handle = 'XX'.(count($this->openproviderCustomers) + 100).'-NL';
                $this->openproviderCustomers[$handle] = $body;

                return $this->openproviderReply(['handle' => $handle]);
            }

            if ($method === 'GET' && str_starts_with($path, 'customers/')) {
                $customer = $this->openproviderCustomers[rawurldecode(substr($path, 10))] ?? null;

                return $customer === null ? $this->openproviderError(1201, 'Customer not found', 404) : $this->openproviderReply($customer);
            }

            if ($path === 'dns/nameservers' && $method === 'POST') {
                $this->openproviderHosts[$body['name']] = $body['ip'];

                return $this->openproviderReply(['success' => true]);
            }

            if (str_starts_with($path, 'dns/nameservers/')) {
                $host = rawurldecode(substr($path, 16));
                $method === 'DELETE' ? $this->openproviderHosts = array_diff_key($this->openproviderHosts, [$host => 1]) : $this->openproviderHosts[$host] = $body['ip'];

                return $this->openproviderReply(['success' => true]);
            }

            if ($method === 'GET' && $path === 'tlds') {
                return $this->openproviderReply(['results' => array_map(fn (string $tld, array $prices) => ['name' => $tld, 'prices' => array_map(
                    fn (float $p) => ['product' => ['price' => $p * 2, 'currency' => 'USD'], 'reseller' => ['price' => $p, 'currency' => 'EUR']], $prices,
                )], array_keys($this->openproviderPrices), $this->openproviderPrices), 'total' => count($this->openproviderPrices)]);
            }

            return $this->openproviderError(404, "Unknown call {$method} {$path}", 404);
        });
    }

    /** @param  array<string, mixed>  $data */
    private function openproviderReply(array $data): PromiseInterface
    {
        return Http::response(['code' => 0, 'desc' => '', 'data' => $data]);
    }

    private function openproviderError(int $code, string $desc, int $status = 400): PromiseInterface
    {
        return Http::response(['code' => $code, 'desc' => $desc, 'data' => null], $status);
    }

    /** @return list<string> "METHOD path" of the calls, in order */
    protected function openproviderCallNames(): array
    {
        return array_column($this->openproviderCalls, 0);
    }
}

<?php

namespace Tests;

use App\Models\Registrar;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\GoDaddy\GoDaddyClient;

/**
 * A tiny in-memory GoDaddy account: keeps domains and answers the v1 calls GaiaCP uses. $godaddyCalls records
 * ["METHOD path", body] pairs.
 */
trait FakesGoDaddy
{
    /** @var array<string, array<string, mixed>> by domain name */
    protected array $godaddyDomains = [];

    /** @var list<array{0: string, 1: array<mixed>}> */
    protected array $godaddyCalls = [];

    protected Registrar $godaddyRegistrar;

    protected function fakeGoDaddy(): void
    {
        $this->godaddyRegistrar = Registrar::factory()->withTlds(['.com', '.net'])->create([
            'name' => 'GoDaddy', 'module' => 'godaddy', 'credentials' => ['api_key' => 'key1', 'api_secret' => 'secret'],
        ]);

        Http::fake(function (Request $request) {
            $url = strtok($request->url(), '?');
            $base = str_starts_with($url, GoDaddyClient::TEST_URL) ? GoDaddyClient::TEST_URL : GoDaddyClient::LIVE_URL;

            if (! str_starts_with($url, $base.'/v1/')) {
                return Http::response('Not found', 404);
            }

            if ($request->header('Authorization')[0] !== 'sso-key key1:secret') {
                return Http::response(['code' => 'UNABLE_TO_AUTHENTICATE', 'message' => 'Unauthorized : Could not authenticate API key/secret'], 401);
            }

            $path = substr($url, strlen($base) + 1);
            $method = $request->method();
            $body = $method === 'GET' ? $request->data() : (json_decode($request->body(), true) ?: []);
            $this->godaddyCalls[] = ["{$method} {$path}", $body];

            if ($path === 'v1/domains/available') {
                return Http::response(['available' => ! isset($this->godaddyDomains[$body['domain']]), 'domain' => $body['domain'], 'definitive' => false]);
            }

            if ($path === 'v1/domains/agreements') {
                return Http::response([['agreementKey' => 'DNRA', 'title' => 'Domain Name Registration Agreement', 'url' => 'https://example.com']]);
            }

            if ($path === 'v1/domains/purchase') {
                $this->godaddyDomains[$body['domain']] = [
                    'domain' => $body['domain'], 'expires' => now()->addYears($body['period'])->format('Y-m-d\T23:59:59.000\Z'), 'locked' => true,
                    'nameServers' => $body['nameServers'] ?? ['ns01.domaincontrol.com', 'ns02.domaincontrol.com'], 'authCode' => 'Gd#Code1',
                    'contactRegistrant' => $body['contactRegistrant'], 'contactAdmin' => $body['contactAdmin'], 'contactTech' => $body['contactTech'], 'contactBilling' => $body['contactBilling'],
                ];

                return Http::response(['orderId' => 1, 'itemCount' => 1, 'total' => 11990000, 'currency' => 'USD']);
            }

            if (! preg_match('#^v1/domains/([^/]+)(/renew|/transfer|/contacts)?$#', $path, $m)) {
                return Http::response(['code' => 'NOT_FOUND', 'message' => 'Unknown path'], 404);
            }

            $name = $m[1];

            if (($m[2] ?? '') === '/transfer') {
                return Http::response(['orderId' => 2, 'itemCount' => 1, 'total' => 8990000, 'currency' => 'USD']);
            }

            if (! isset($this->godaddyDomains[$name])) {
                return Http::response(['code' => 'NOT_FOUND', 'message' => 'The given domain is not registered, or does not have a zone file'], 404);
            }

            switch ($m[2] ?? '') {
                case '/renew':
                    $this->godaddyDomains[$name]['expires'] = now()->parse(substr($this->godaddyDomains[$name]['expires'], 0, 10))->addYears($body['period'])->format('Y-m-d\T23:59:59.000\Z');

                    return Http::response(['orderId' => 3, 'itemCount' => 1, 'total' => 11990000, 'currency' => 'USD']);
                case '/contacts':
                    $this->godaddyDomains[$name] = [...$this->godaddyDomains[$name], ...$body];

                    return Http::response('', 204);
            }

            if ($method === 'PATCH') {
                $this->godaddyDomains[$name] = [...$this->godaddyDomains[$name], ...$body];

                return Http::response('', 204);
            }

            return Http::response($this->godaddyDomains[$name]);
        });
    }

    /** @return list<string> "METHOD path" of the calls, in order */
    protected function godaddyCallNames(): array
    {
        return array_column($this->godaddyCalls, 0);
    }
}

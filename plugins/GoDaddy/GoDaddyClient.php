<?php

namespace Plugins\GoDaddy;

use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * GoDaddy Domains API (v1): JSON over HTTPS with the header "Authorization: sso-key KEY:SECRET", and X-Shopper-Id when
 * the domains sit in a reseller's sub-account. Errors come back with an HTTP error status and {code, message, fields}.
 */
class GoDaddyClient
{
    public const LIVE_URL = 'https://api.godaddy.com';

    public const TEST_URL = 'https://api.ote-godaddy.com';

    /** @param  array{api_key?: string|null, api_secret?: string|null, shopper_id?: string|null, test_mode?: bool, verify_tls?: bool}  $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return filled($this->config['api_key'] ?? null) && filled($this->config['api_secret'] ?? null);
    }

    public function url(string $path): string
    {
        return (($this->config['test_mode'] ?? false) ? self::TEST_URL : self::LIVE_URL).'/'.ltrim($path, '/');
    }

    /**
     * The decoded reply (an empty array for 204 No Content).
     *
     * @param  array<string, mixed>|list<mixed>  $body  JSON body (query string for GET)
     * @param  list<string|null>  $secrets  values to keep out of the module log (an EPP code)
     * @return array<mixed>
     */
    public function call(string $method, string $path, array $body = [], array $secrets = [], int $timeout = 60): array
    {
        if (! $this->configured()) {
            throw new ProvisioningException('GoDaddy is not set up: enter the API key and secret under Settings → Registrars.');
        }

        $action = $method.' /'.ltrim(preg_replace('#/domains/[^/?]+#', '/domains/{domain}', $path) ?? $path, '/');

        try {
            $request = Http::acceptJson()
                ->withHeaders(array_filter([
                    'Authorization' => 'sso-key '.$this->config['api_key'].':'.$this->config['api_secret'],
                    'X-Shopper-Id' => filled($this->config['shopper_id'] ?? null) ? (string) $this->config['shopper_id'] : null,
                ]))
                ->withOptions(['verify' => (bool) ($this->config['verify_tls'] ?? true)])
                ->withMiddleware(ModuleLogger::middleware('godaddy', $action, [$this->config['api_key'], $this->config['api_secret'], ...$secrets], ['authCode']))
                ->connectTimeout(30)
                ->timeout($timeout);

            $response = $method === 'GET'
                ? $request->get($this->url($path), $body)
                : $request->asJson()->send($method, $this->url($path), ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new ProvisioningException('Cannot reach GoDaddy: '.preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage()), previous: $e);
        }

        $json = $response->json();

        if ($response->failed()) {
            $reason = is_array($json) ? trim((string) ($json['message'] ?? $json['code'] ?? '')) : '';
            $fields = is_array($json) && is_array($json['fields'] ?? null)
                ? implode(', ', array_filter(array_map(fn ($f) => is_array($f) ? trim(($f['path'] ?? '').': '.($f['message'] ?? ''), ': ') : null, $json['fields'])))
                : '';

            throw new ProvisioningException($reason !== ''
                ? 'GoDaddy: '.$reason.($fields !== '' ? " ({$fields})" : '')
                : "GoDaddy {$action} failed with HTTP {$response->status()}.");
        }

        if ($response->status() === 204 || trim($response->body()) === '') {
            return [];
        }

        if (! is_array($json)) {
            throw new ProvisioningException("GoDaddy {$action} returned an unexpected response.");
        }

        return $json;
    }
}

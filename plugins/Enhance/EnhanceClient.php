<?php

namespace Plugins\Enhance;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Enhance REST client: JSON to https://host/api/… with an access token as "Authorization: Bearer". Lists come
 * back as {"items": [...]}, new things as {"id": …}, errors as JSON with `code` and `detail` (or `message`). Paths and
 * fields follow the open-source Enhance modules for WHMCS (webdighost/enhance-whmcs) and Paymenter, since Enhance's
 * own API reference (apidocs.enhance.com) could not be reached when this was written.
 */
class EnhanceClient
{
    public function __construct(private Server $server) {}

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>|string|null the decoded reply
     */
    public function get(string $path, array $query = [], int $timeout = 30): array|string|null
    {
        return $this->send('GET', $path, $query, [], [], $timeout);
    }

    /**
     * The same, or null when Enhance answers 404 (it no longer exists).
     *
     * @param  array<string, scalar>  $query
     * @return array<mixed>|string|null
     */
    public function find(string $path, array $query = [], int $timeout = 30): array|string|null
    {
        return $this->send('GET', $path, $query, [], [], $timeout, missingIsNull: true);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, scalar>  $query
     * @param  list<string>  $secrets  values to mask in the module log (passwords)
     * @return array<mixed>|string|null
     */
    public function post(string $path, array $body = [], array $query = [], array $secrets = [], int $timeout = 60): array|string|null
    {
        return $this->send('POST', $path, $query, $body, $secrets, $timeout);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $secrets
     * @return array<mixed>|string|null
     */
    public function patch(string $path, array $body, array $secrets = [], int $timeout = 60): array|string|null
    {
        return $this->send('PATCH', $path, [], $body, $secrets, $timeout);
    }

    /**
     * Deletes; a 404 counts as done (already gone).
     *
     * @param  array<string, scalar>  $query
     */
    public function delete(string $path, array $query = [], int $timeout = 120): void
    {
        $this->send('DELETE', $path, $query, [], [], $timeout, missingIsNull: true);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>  $body
     * @param  list<string>  $secrets
     * @return array<mixed>|string|null
     */
    private function send(string $method, string $path, array $query, array $body, array $secrets, int $timeout, bool $missingIsNull = false): array|string|null
    {
        $token = (string) $this->server->api_token;
        $url = $this->baseUrl().'/api'.$path.($query ? '?'.http_build_query($query) : '');

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->withOptions(['verify' => $this->server->verify_tls])
                ->withMiddleware(ModuleLogger::middleware('enhance', "{$method} {$path}", [$token, ...$secrets]))
                ->connectTimeout(5)
                ->timeout($timeout);

            $response = match ($method) {
                'GET' => $request->get($url),
                'DELETE' => $request->delete($url),
                'PATCH' => $request->patch($url, $body),
                default => $request->post($url, $body),
            };
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}: {$reason}", previous: $e);
        }

        if ($missingIsNull && $response->status() === 404) {
            return null;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProvisioningException("Enhance rejected the access token for {$this->server->hostname} (HTTP {$response->status()}).");
        }

        // The control panel's web page instead of the API (a wrong hostname or port).
        if (str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')) {
            throw new ProvisioningException("Enhance {$method} {$path}: the server answered with a web page, not the API. Check the hostname and port.");
        }

        $data = $response->json();

        if ($response->failed()) {
            $reason = is_array($data)
                ? trim(implode(': ', array_filter([(string) ($data['code'] ?? ''), (string) ($data['detail'] ?? $data['message'] ?? '')])))
                : trim(strip_tags($response->body()));

            throw new ProvisioningException("Enhance {$method} {$path} failed with HTTP {$response->status()}".($reason !== '' ? ": {$reason}" : '.'));
        }

        return is_array($data) || is_string($data) ? $data : null;
    }

    /** https://hostname, with the port when it isn't 443. */
    public function baseUrl(): string
    {
        $port = (int) $this->server->port;

        return 'https://'.$this->server->hostname.($port && $port !== 443 ? ':'.$port : '');
    }
}

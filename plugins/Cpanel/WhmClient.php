<?php

namespace Plugins\Cpanel;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal WHM API 1 client: POST https://host:2087/json-api/<function>?api.version=1
 * with "Authorization: whm <user>:<token>". Parameters go in the POST body so
 * passwords never end up in URLs or web server logs.
 */
class WhmClient
{
    public function __construct(private Server $server) {}

    /** The WHM user the API token belongs to (root or a reseller). */
    public function username(): string
    {
        return (string) $this->server->username ?: 'root';
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed> the response's "data" member
     */
    public function call(string $function, array $params = [], int $timeout = 30): array
    {
        $url = sprintf('https://%s:%d/json-api/%s?api.version=1', $this->server->hostname, $this->server->port, $function);

        try {
            $response = Http::withHeaders(['Authorization' => "whm {$this->server->username}:{$this->server->api_token}"])
                ->withOptions(['verify' => $this->server->verify_tls])
                ->withMiddleware(ModuleLogger::middleware('cpanel', $function, [$this->server->api_token, ...ModuleLogger::secretsIn($params)]))
                ->acceptJson()
                ->asForm()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post($url, $params);
        } catch (ConnectionException $e) {
            // Keep "cURL error 28: Operation timed out…" but drop the curl.se link and URL that follow.
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port}: {$reason}", previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProvisioningException("WHM rejected the API token for {$this->server->hostname} (HTTP {$response->status()}).");
        }

        if ($response->failed()) {
            throw new ProvisioningException("WHM {$function} failed with HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json) || ! isset($json['metadata'])) {
            throw new ProvisioningException("WHM {$function} returned an unexpected response.");
        }

        if ((int) ($json['metadata']['result'] ?? 0) !== 1) {
            $reason = trim((string) ($json['metadata']['reason'] ?? 'unknown error'));

            throw new ProvisioningException("WHM {$function}: {$reason}");
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}

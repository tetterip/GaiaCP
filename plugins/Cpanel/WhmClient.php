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

    /** @var array{0: string, 1: string}|null the cpsess token and whostmgrsession cookie of a WHM session, for WP Toolkit */
    private ?array $wpToolkitSession = null;

    /**
     * A call to WP Toolkit's REST API inside WHM (https://host:2087/cpsess…/cgi/wpt/index.php/v1/…). It takes a WHM
     * session, not the API token: create_user_session for the token's user into WHM, whose URL is opened once (without
     * following its redirect) for the whostmgrsession cookie. Redirects of the call are not followed: the login call
     * answers with one.
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, location: ?string, json: mixed}
     */
    public function wpToolkit(string $method, string $path, array $body = [], int $timeout = 30): array
    {
        [$token, $cookie] = $this->wpToolkitSession ??= $this->whmSession();

        $secrets = [$this->server->api_token, $token, $cookie, ...ModuleLogger::secretsIn($body)];

        try {
            $response = Http::withHeaders(['Cookie' => "whostmgrsession={$cookie}"])
                ->withOptions(['verify' => $this->server->verify_tls])
                ->withMiddleware(ModuleLogger::middleware('cpanel', 'wp-toolkit '.$method.' '.$path, $secrets, str_ends_with($path, '/login') ? ['url', 'link', 'loginUrl'] : []))
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->send($method, sprintf('https://%s:%d/%s/cgi/wpt/index.php/v1/%s', $this->server->hostname, $this->server->port, $token, ltrim($path, '/')), $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port}: {$reason}", previous: $e);
        }

        if ($response->status() === 404) {
            throw new ProvisioningException("WP Toolkit is not installed on {$this->server->hostname}.");
        }

        if ($response->status() >= 400) {
            $error = is_array($response->json()) ? ($response->json('message') ?? $response->json('error') ?? '') : '';

            throw new ProvisioningException(trim("WP Toolkit {$path} failed with HTTP {$response->status()}. ".(is_string($error) ? $error : '')));
        }

        return ['status' => $response->status(), 'location' => $response->header('Location') ?: null, 'json' => $response->json()];
    }

    /** @return array{0: string, 1: string} the cpsess token of a new WHM session and its whostmgrsession cookie */
    private function whmSession(): array
    {
        $url = (string) ($this->call('create_user_session', ['user' => $this->username(), 'service' => 'whostmgrd'])['url'] ?? '');

        if (! preg_match('#^https?://[^/]+/(cpsess\d+)/#', $url, $m)) {
            throw new ProvisioningException('WHM create_user_session returned no session URL.');
        }

        try {
            $response = Http::withOptions(['verify' => $this->server->verify_tls])
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(30)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port} to open a WHM session.", previous: $e);
        }

        foreach ($response->toPsrResponse()->getHeader('Set-Cookie') as $header) {
            if (preg_match('/(?:^|;\s*)whostmgrsession=([^;]+)/', (string) $header, $c)) {
                return [$m[1], $c[1]];
            }
        }

        throw new ProvisioningException('WHM opened no session for WP Toolkit.');
    }
}

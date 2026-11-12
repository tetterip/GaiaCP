<?php

namespace Plugins\Openprovider;

use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Openprovider REST API (v1beta): POST /auth/login with the username and password gives a bearer token, then JSON
 * requests. Replies are {"code": 0, "data": {…}} on success, or a non-zero code with the reason in "desc". The token
 * is kept for the life of this object (one job or page).
 */
class OpenproviderClient
{
    public const LIVE_URL = 'https://api.openprovider.eu/v1beta';

    public const SANDBOX_URL = 'http://api.sandbox.openprovider.nl:8480/v1beta';

    private ?string $token = null;

    /** @param  array{username?: string|null, password?: string|null, sandbox?: bool, verify_tls?: bool}  $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return filled($this->config['username'] ?? null) && filled($this->config['password'] ?? null);
    }

    public function url(string $path): string
    {
        return (($this->config['sandbox'] ?? false) ? self::SANDBOX_URL : self::LIVE_URL).'/'.ltrim($path, '/');
    }

    /**
     * The "data" of a successful call.
     *
     * @param  array<string, mixed>  $body  JSON body (query string for GET)
     * @param  list<string|null>  $secrets  values to keep out of the module log (an EPP code)
     * @return array<string, mixed>
     */
    public function call(string $method, string $path, array $body = [], array $secrets = [], int $timeout = 60): array
    {
        if (! $this->configured()) {
            throw new ProvisioningException('Openprovider is not set up: enter the username and password under Settings → Registrars.');
        }

        $this->token ??= $this->login();

        return $this->data($this->send($method, $path, $body, [$this->token, ...$secrets], $timeout, $this->token), $method, $path);
    }

    private function login(): string
    {
        $data = $this->data($this->send('POST', 'auth/login', [
            'username' => $this->config['username'],
            'password' => $this->config['password'],
            'ip' => '0.0.0.0',
        ], [$this->config['password']], 30), 'POST', 'auth/login');

        $token = $data['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new ProvisioningException('Openprovider returned no login token.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string|null>  $secrets
     */
    private function send(string $method, string $path, array $body, array $secrets, int $timeout, ?string $token = null): Response
    {
        $action = $method.' /'.preg_replace('/\d+/', '{id}', ltrim($path, '/'));

        try {
            $request = Http::acceptJson()
                ->withOptions(['verify' => (bool) ($this->config['verify_tls'] ?? true)])
                ->withMiddleware(ModuleLogger::middleware('openprovider', $action, $secrets, ['token', 'auth_code']))
                ->connectTimeout(30)
                ->timeout($timeout);

            if ($token !== null) {
                $request = $request->withToken($token);
            }

            return $method === 'GET'
                ? $request->get($this->url($path), $body)
                : $request->asJson()->send($method, $this->url($path), $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new ProvisioningException('Cannot reach Openprovider: '.preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage()), previous: $e);
        }
    }

    /** @return array<string, mixed> */
    private function data(Response $response, string $method, string $path): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            throw new ProvisioningException($response->failed()
                ? "Openprovider {$method} /{$path} failed with HTTP {$response->status()}."
                : "Openprovider {$method} /{$path} returned an unexpected response.");
        }

        if ((int) ($json['code'] ?? 0) !== 0 || $response->failed()) {
            $reason = trim((string) ($json['desc'] ?? '')) ?: "HTTP {$response->status()}";

            throw new ProvisioningException("Openprovider: {$reason}");
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}

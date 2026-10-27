<?php

namespace Plugins\DirectAdmin;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal DirectAdmin legacy API client: https://host:2222/CMD_API_<COMMAND> with HTTP basic auth (the admin or a
 * reseller, with its password or a login key), parameters as a form POST (GET for reads). Replies are URL-encoded
 * ("error=0&text=…", lists as "list[]=a&list[]=b"); a failure is "error=1" with `text` and `details`. Request shapes
 * follow DirectAdmin's own example scripts and the omines/directadmin client.
 */
class DirectAdminClient
{
    public function __construct(private Server $server) {}

    /**
     * @param  array<string, scalar>  $params
     * @param  list<string>  $secrets  values to mask in the module log (passwords)
     * @return array<string, mixed> the parsed reply
     *
     * @throws ProvisioningException
     */
    public function post(string $command, array $params = [], array $secrets = [], int $timeout = 30): array
    {
        return $this->send('POST', $command, $params, $secrets, $timeout);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function get(string $command, array $query = [], int $timeout = 30): array
    {
        return $this->send('GET', $command, $query, [], $timeout);
    }

    /**
     * @param  array<string, scalar>  $params
     * @param  list<string>  $secrets
     * @return array<string, mixed>
     */
    private function send(string $method, string $command, array $params, array $secrets, int $timeout): array
    {
        $token = (string) $this->server->api_token;
        $url = sprintf('https://%s:%d/CMD_API_%s', $this->server->hostname, $this->server->port, $command);

        try {
            $request = Http::withBasicAuth((string) $this->server->username, $token)
                ->withOptions(['verify' => $this->server->verify_tls])
                ->withMiddleware(ModuleLogger::middleware('directadmin', $command, [$token, ...$secrets]))
                ->connectTimeout(5)
                ->timeout($timeout);

            $response = $method === 'POST' ? $request->asForm()->post($url, $params) : $request->get($url, $params);
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port}: {$reason}", previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProvisioningException("DirectAdmin rejected the login for {$this->server->hostname} (HTTP {$response->status()}).");
        }

        // A login page or error page instead of an API reply (e.g. a wrong port or a user without API access).
        if (str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')) {
            throw new ProvisioningException("DirectAdmin {$command}: the server answered with a web page, not the API. Check the port and that the login may use the API.");
        }

        if ($response->failed()) {
            throw new ProvisioningException("DirectAdmin {$command} failed with HTTP {$response->status()}.");
        }

        $data = self::parse($response->body());

        if ((string) ($data['error'] ?? '0') !== '0') {
            $reason = trim(implode(': ', array_filter([trim((string) ($data['text'] ?? '')), trim(strip_tags((string) ($data['details'] ?? '')))]))) ?: 'unknown error';

            throw new ProvisioningException("DirectAdmin {$command}: {$reason}");
        }

        return $data;
    }

    /**
     * A URL-encoded reply as an array; DirectAdmin writes some characters as "&#NN;" first.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $body): array
    {
        $body = preg_replace_callback('/&#(\d{2,3});?/', fn (array $m) => chr((int) $m[1]), trim($body));
        parse_str($body, $data);

        return $data;
    }
}

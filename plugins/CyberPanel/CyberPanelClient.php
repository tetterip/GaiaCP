<?php

namespace Plugins\CyberPanel;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal CyberPanel client, from CyberPanel's own source (usmannasir/cyberpanel, api/ and cloudAPI/):
 *
 * - api(): https://host:8090/api/<endpoint>, a JSON POST carrying `adminUser` / `adminPass` (the server's username and
 *   password). Each endpoint answers with its own status key set to 1 (e.g. `createWebSiteStatus`), or 0 with
 *   `error_message`; the login must have API access (CyberPanel → Users → API Access).
 * - cloud(): https://host:8090/cloudAPI/, a JSON POST with `serverUserName` and `controller`, authorized by the user's
 *   API token in the Authorization header. Only CyberPanel's `admin` user may use it. The token comes from
 *   /api/verifyConn (`apiToken`); a CyberPanel too old to send one takes "Basic base64(user:password)".
 */
class CyberPanelClient
{
    private ?string $token = null;

    public function __construct(private Server $server) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  string|null  $statusKey  the reply's key that is 1 on success; null = any reply that is JSON
     * @param  list<string>  $secrets  values to mask in the module log (passwords)
     * @return array<mixed> the decoded reply
     *
     * @throws ProvisioningException
     */
    public function api(string $endpoint, array $params, ?string $statusKey, array $secrets = [], int $timeout = 30, array $secretResponseKeys = []): array
    {
        $password = (string) $this->server->api_token;
        $credentials = $endpoint === 'cyberPanelVersion'
            ? ['username' => $this->server->username, 'password' => $password]
            : ['adminUser' => $this->server->username, 'adminPass' => $password];

        $response = $this->send($endpoint, $this->url('api/'.$endpoint), [...$credentials, ...$params], [], [$password, ...$secrets], $timeout, $secretResponseKeys);

        return $this->decode($endpoint, $response, $statusKey);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     *
     * @throws ProvisioningException
     */
    public function cloud(string $controller, array $params = [], int $timeout = 30): array
    {
        $token = $this->token();
        $response = $this->send($controller, $this->url('cloudAPI/'), ['serverUserName' => $this->server->username, 'controller' => $controller, ...$params], ['Authorization' => $token], [$token], $timeout);

        return $this->decode($controller, $response, 'status');
    }

    /** The login's API token for the cloud API, read once. */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $reply = $this->api('verifyConn', [], 'verifyConn', secretResponseKeys: ['apiToken']);

        return $this->token = filled($reply['apiToken'] ?? null)
            ? (string) $reply['apiToken']
            : 'Basic '.base64_encode($this->server->username.':'.$this->server->api_token);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @param  list<string>  $secrets
     * @param  list<string>  $secretResponseKeys
     */
    private function send(string $action, string $url, array $body, array $headers, array $secrets, int $timeout, array $secretResponseKeys = []): Response
    {
        // A secret as it reads inside the JSON body too ("/" and quotes escaped).
        $secrets = array_values(array_unique([...$secrets, ...array_map(fn (string $s) => substr((string) json_encode($s), 1, -1), $secrets)]));

        try {
            return Http::withOptions(['verify' => $this->server->verify_tls])
                ->withHeaders($headers)
                ->withMiddleware(ModuleLogger::middleware('cyberpanel', $action, $secrets, $secretResponseKeys))
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post($url, $body);
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port}: {$reason}", previous: $e);
        }
    }

    /** @return array<mixed> */
    private function decode(string $action, Response $response, ?string $statusKey): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            // CyberPanel's login page, a 404 page or a proxy error instead of the API.
            throw new ProvisioningException("CyberPanel {$action}: the server answered HTTP {$response->status()} without the API's reply. Check the port (8090) and that the login has API access.");
        }

        $error = trim((string) ($data['error_message'] ?? ''));
        $failed = $statusKey !== null
            ? (string) ($data[$statusKey] ?? '0') === '0'
            : $response->failed() || (array_key_exists('status', $data) && (string) $data['status'] === '0');

        if ($failed) {
            throw new ProvisioningException("CyberPanel {$action}: ".($error !== '' && strcasecmp($error, 'None') !== 0 ? $error : "failed with HTTP {$response->status()}"));
        }

        return $data;
    }

    private function url(string $path): string
    {
        return sprintf('https://%s:%d/%s', $this->server->hostname, $this->server->port, $path);
    }
}

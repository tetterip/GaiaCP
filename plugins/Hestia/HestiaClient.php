<?php

namespace Plugins\Hestia;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal HestiaCP API client: a form POST to https://host:8083/api/ with `cmd` (a v-* command) and its arguments as
 * `arg1`… (web/api/index.php in hestiacp/hestiacp). Authenticates with an access key ("ACCESS_KEY:SECRET_KEY",
 * HestiaCP 1.6+) or an old API key (v-generate-api-key) when the server's username is empty, else with the admin
 * user and password. Arguments, passwords included, go in the POST body, never in the URL.
 *
 * Access keys answer with a `Hestia-Exit-Code` header and an HTTP error status on failure; the password login and old
 * keys answer HTTP 200 with the command's output, which starts with "Error:" on failure.
 */
class HestiaClient
{
    /** HestiaCP's E_NOTEXIST exit code: the user (or other object) is not there. */
    public const NOT_FOUND = 3;

    public function __construct(private Server $server) {}

    /**
     * Runs a command and returns its output ("OK" or empty for most commands that change something).
     *
     * @param  list<string>  $args  arg1, arg2, … in order
     * @param  list<string>  $secrets  arguments to mask in the module log (passwords)
     *
     * @throws ProvisioningException with the exit code as its code (NOT_FOUND = missing)
     */
    public function run(string $command, array $args = [], array $secrets = [], int $timeout = 30): string
    {
        $params = ['cmd' => $command];
        foreach (array_values($args) as $i => $arg) {
            $params['arg'.($i + 1)] = $arg;
        }

        $token = (string) $this->server->api_token;

        try {
            $response = Http::withOptions(['verify' => $this->server->verify_tls])
                ->withMiddleware(ModuleLogger::middleware('hestia', $command, [$token, ...explode(':', $token), ...$secrets]))
                ->asForm()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post(sprintf('https://%s:%d/api/', $this->server->hostname, $this->server->port), [...$this->credentials($token), ...$params]);
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port}: {$reason}", previous: $e);
        }

        $body = trim($response->body());
        $header = $response->header('Hestia-Exit-Code');
        $exitCode = is_numeric($header) ? (int) $header : null;
        $error = str_starts_with($body, 'Error:');

        if (! $error && ($exitCode ?? 0) === 0 && $response->successful()) {
            return $body;
        }

        $message = trim(preg_replace('/^Error:\s*/', '', $body)) ?: "HTTP {$response->status()}";

        // Without the header (password login) "doesn't exist" is how HestiaCP says E_NOTEXIST.
        $exitCode ??= str_contains($message, "doesn't exist") ? self::NOT_FOUND : 0;

        throw new ProvisioningException("HestiaCP {$command}: {$message}", $exitCode);
    }

    /**
     * Runs a list command with "json" as its last argument and returns the decoded output.
     *
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    public function list(string $command, array $args = [], int $timeout = 30): array
    {
        $data = json_decode($this->run($command, [...$args, 'json'], timeout: $timeout), true);

        if (! is_array($data)) {
            throw new ProvisioningException("HestiaCP {$command} returned an unexpected response.");
        }

        return $data;
    }

    /** @return array<string, string> */
    private function credentials(string $token): array
    {
        if (filled($this->server->username)) {
            return ['user' => $this->server->username, 'password' => $token];
        }

        if (substr_count($token, ':') === 1) {
            [$key, $secret] = explode(':', $token);

            return ['access_key' => trim($key), 'secret_key' => trim($secret)];
        }

        return ['hash' => $token];
    }
}

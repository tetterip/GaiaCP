<?php

namespace Plugins\Virtualmin;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Virtualmin remote API client: https://host:10000/virtual-server/remote.cgi with `program` (a Virtualmin
 * command such as create-domain), `json=1` and the command's options, as the master administrator with HTTP basic
 * auth. Options go in a form POST body, so passwords never sit in a URL or a web server log; an option without a value
 * (a flag such as --multiline) is sent empty. Answers {"status": "success"|"failure", "error": …, "data" | "output": …}.
 */
class VirtualminClient
{
    public function __construct(private Server $server) {}

    /**
     * Runs a command and returns its decoded answer.
     *
     * @param  array<string, string>  $options  without the leading "--"; '' for a flag
     * @param  list<string>  $secrets  values to mask in the module log (passwords)
     * @return array<string, mixed>
     *
     * @throws ProvisioningException with Virtualmin's error
     */
    public function run(string $program, array $options = [], array $secrets = [], int $timeout = 60): array
    {
        $password = (string) $this->server->api_token;

        try {
            $response = Http::withOptions(['verify' => $this->server->verify_tls])
                ->withMiddleware(ModuleLogger::middleware('virtualmin', $program, [$password, ...$secrets]))
                ->withBasicAuth((string) $this->server->username, $password)
                ->acceptJson()
                ->asForm()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post(sprintf('https://%s:%d/virtual-server/remote.cgi', $this->server->hostname, $this->server->port), [
                    'program' => $program,
                    'json' => '1',
                    ...$options,
                ]);
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port}: {$reason}", previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProvisioningException("Virtualmin {$program}: login refused (HTTP {$response->status()}). Use the master administrator (root) and its password.");
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['status'])) {
            throw new ProvisioningException("Virtualmin {$program}: unexpected answer (HTTP {$response->status()}).");
        }

        if ($data['status'] !== 'success') {
            $error = trim((string) ($data['error'] ?? $data['output'] ?? '')) ?: 'failed';

            throw new ProvisioningException("Virtualmin {$program}: {$error}");
        }

        return $data;
    }

    /**
     * A list command (list-domains, list-plans) with --multiline: each item's name and its fields, lower case with
     * underscores ("server_quota_used"), each a list of values.
     *
     * @param  array<string, string>  $options
     * @return list<array{name: string, values: array<string, list<string>>}>
     */
    public function list(string $program, array $options = [], int $timeout = 60): array
    {
        $data = $this->run($program, [...$options, 'multiline' => ''], timeout: $timeout)['data'] ?? [];

        return array_values(array_map(fn (array $item) => [
            'name' => (string) ($item['name'] ?? ''),
            'values' => array_map(fn ($value) => array_map('strval', (array) $value), (array) ($item['values'] ?? [])),
        ], array_filter((array) $data, 'is_array')));
    }
}

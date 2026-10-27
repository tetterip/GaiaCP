<?php

namespace Plugins\Proxmox;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Minimal Proxmox VE API client: https://host:8006/api2/json/<path> with an API token (`Authorization:
 * PVEAPIToken=USER@REALM!TOKENID=SECRET`; the server's username is the token id, its API token the secret). Changes go
 * as form fields; every answer is `{"data": …}`. A failure is an HTTP error whose reason phrase says why ("VM 100 does
 * not exist"), with the fields that failed under `errors` on a 400. Calls that run as a task (clone, start, delete, …)
 * answer with its UPID; waitForTask() follows it until it ends.
 */
class ProxmoxClient
{
    /** How often and how long a task is followed: a full clone of a big disk can take several minutes. */
    private const TASK_POLL_SECONDS = 2;

    private const TASK_MAX_SECONDS = 900;

    public function __construct(private Server $server) {}

    public function get(string $path, array $query = []): mixed
    {
        return $this->send('get', $path, $query);
    }

    /** @param  list<string>  $secrets  values to mask in the module log (passwords) */
    public function post(string $path, array $params = [], array $secrets = []): mixed
    {
        return $this->send('post', $path, $params, $secrets);
    }

    /** @param  list<string>  $secrets */
    public function put(string $path, array $params = [], array $secrets = []): mixed
    {
        return $this->send('put', $path, $params, $secrets);
    }

    public function delete(string $path, array $query = []): mixed
    {
        return $this->send('delete', $path, $query);
    }

    /**
     * Follows a task (a UPID, "UPID:node:…") until it stops; throws when it did not end OK ("WARNINGS: 1" is fine).
     */
    public function waitForTask(string $node, mixed $upid): void
    {
        if (! is_string($upid) || ! str_starts_with($upid, 'UPID:')) {
            return; // done at once, nothing to follow
        }

        $path = '/nodes/'.rawurlencode($node).'/tasks/'.rawurlencode($upid).'/status';

        for ($waited = 0; $waited <= self::TASK_MAX_SECONDS; $waited += self::TASK_POLL_SECONDS) {
            $status = (array) $this->get($path);

            if (($status['status'] ?? null) === 'stopped') {
                $exit = (string) ($status['exitstatus'] ?? '');

                if ($exit === 'OK' || str_starts_with($exit, 'WARNINGS')) {
                    return;
                }

                throw new ProvisioningException('Proxmox task '.($status['type'] ?? '').' failed: '.($exit ?: 'unknown error'));
            }

            Sleep::for(self::TASK_POLL_SECONDS)->seconds();
        }

        throw new ProvisioningException("Proxmox task still running after {$waited} seconds: {$upid}");
    }

    private function send(string $method, string $path, array $params = [], array $secrets = []): mixed
    {
        $secret = (string) $this->server->api_token;
        $url = sprintf('https://%s:%d/api2/json%s', $this->server->hostname, $this->server->port, $path);
        $params = array_map(fn ($value) => is_bool($value) ? (int) $value : $value, $params);

        try {
            $request = Http::withOptions(['verify' => $this->server->verify_tls])
                ->withMiddleware(ModuleLogger::middleware('proxmox', strtoupper($method).' '.$path, [$secret, ...$secrets]))
                ->withHeaders(['Authorization' => "PVEAPIToken={$this->server->username}={$secret}"])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(60);

            /** @var Response $response */
            $response = match ($method) {
                'get' => $request->get($url, $params),
                'delete' => $request->delete($params === [] ? $url : $url.'?'.http_build_query($params)),
                default => $request->asForm()->{$method}($url, $params),
            };
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port}: {$reason}", previous: $e);
        }

        if ($response->successful()) {
            return $response->json('data');
        }

        $errors = collect((array) $response->json('errors'))
            ->map(fn ($error, $field) => is_string($field) ? "{$field}: ".trim((string) $error) : trim((string) $error))
            ->implode('; ');

        $message = trim($response->reason()) ?: "HTTP {$response->status()}";

        throw new ProvisioningException("Proxmox {$path}: {$message}".($errors !== '' ? " ({$errors})" : ''), $response->status());
    }
}

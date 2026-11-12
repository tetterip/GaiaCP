<?php

namespace Plugins\ResellerClub;

use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ResellerClub (LogicBoxes) HTTP API: https://httpapi.com/api/<area>/<call>.json with auth-userid and api-key, the call's
 * parameters as form fields (a list, like ns, as the same name repeated). Reads are GET, changes POST. Replies are JSON;
 * an error is {"status": "ERROR", "message": …} (or "error"), often with an HTTP error status.
 */
class ResellerClubClient
{
    public const LIVE_URL = 'https://httpapi.com/api/';

    public const TEST_URL = 'https://test.httpapi.com/api/';

    /** @param  array{reseller_id?: string|null, api_key?: string|null, test_mode?: bool, verify_tls?: bool}  $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return filled($this->config['reseller_id'] ?? null) && filled($this->config['api_key'] ?? null);
    }

    public function url(string $call): string
    {
        return (($this->config['test_mode'] ?? false) ? self::TEST_URL : self::LIVE_URL).$call.'.json';
    }

    /**
     * The decoded reply (a number or text reply comes back as ['value' => …]).
     *
     * @param  array<string, scalar|list<scalar>|null>  $params
     * @param  list<string|null>  $secrets  values to keep out of the module log (an EPP code)
     * @return array<mixed>
     */
    public function call(string $method, string $call, array $params = [], array $secrets = [], int $timeout = 60): array
    {
        if (! $this->configured()) {
            throw new ProvisioningException('ResellerClub is not set up: enter the reseller ID and API key under Settings → Registrars.');
        }

        $query = self::encode(['auth-userid' => $this->config['reseller_id'], 'api-key' => $this->config['api_key'], ...$params]);

        try {
            $request = Http::acceptJson()
                ->withOptions(['verify' => (bool) ($this->config['verify_tls'] ?? true)])
                ->withMiddleware(ModuleLogger::middleware('resellerclub', $call, [$this->config['api_key'], ...$secrets], ['domsecret']))
                ->connectTimeout(30)
                ->timeout($timeout);

            $response = $method === 'GET'
                ? $request->get($this->url($call).'?'.$query)
                : $request->withBody($query, 'application/x-www-form-urlencoded')->post($this->url($call));
        } catch (ConnectionException $e) {
            throw new ProvisioningException('Cannot reach ResellerClub: '.preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage()), previous: $e);
        }

        $json = $response->json();

        if (is_array($json) && in_array(strtolower((string) ($json['status'] ?? '')), ['error', 'failed'], true)) {
            throw new ProvisioningException('ResellerClub: '.(trim((string) ($json['message'] ?? $json['error'] ?? '')) ?: 'unknown error'));
        }

        if (is_array($json) && strtolower((string) ($json['actionstatus'] ?? '')) === 'failed') {
            throw new ProvisioningException('ResellerClub: '.(trim((string) ($json['actionstatusdesc'] ?? '')) ?: 'the action failed'));
        }

        if ($response->failed()) {
            throw new ProvisioningException("ResellerClub {$call} failed with HTTP {$response->status()}.");
        }

        if (is_array($json)) {
            return $json;
        }

        if (is_scalar($json)) {
            return ['value' => $json];
        }

        throw new ProvisioningException("ResellerClub {$call} returned an unexpected response.");
    }

    /** @param  array<string, scalar|list<scalar>|null>  $params  "ns=a&ns=b" for a list, not PHP's ns[0]= */
    public static function encode(array $params): string
    {
        $pairs = [];

        foreach ($params as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                if ($one !== null) {
                    $pairs[] = rawurlencode($name).'='.rawurlencode(is_bool($one) ? ($one ? 'true' : 'false') : (string) $one);
                }
            }
        }

        return implode('&', $pairs);
    }
}

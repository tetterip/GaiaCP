<?php

namespace Plugins\NameSilo;

use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * NameSilo API: a GET to /api/<operation> with version=1, type=xml and key, the operation's own parameters as more
 * query fields. Replies are XML: <namesilo><reply><code>…</code><detail>…</detail>…</reply></namesilo>, where codes
 * 300-302 are success (301 and 302: done, with a warning in detail) and anything else is an error with its text in detail.
 */
class NameSiloClient
{
    public const LIVE_URL = 'https://www.namesilo.com/api/';

    public const SANDBOX_URL = 'https://sandbox.namesilo.com/api/';

    /** Reply codes that mean the operation was done. */
    public const SUCCESS = ['300', '301', '302'];

    /** @param  array{api_key?: string|null, sandbox?: bool, verify_tls?: bool}  $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return filled($this->config['api_key'] ?? null);
    }

    public function url(string $operation): string
    {
        return (($this->config['sandbox'] ?? false) ? self::SANDBOX_URL : self::LIVE_URL).$operation;
    }

    /**
     * The <reply> of a successful call.
     *
     * @param  array<string, scalar|null>  $params
     * @param  list<string|null>  $secrets  values to keep out of the module log (an EPP code)
     */
    public function call(string $operation, array $params = [], array $secrets = [], int $timeout = 60): SimpleXMLElement
    {
        if (! $this->configured()) {
            throw new ProvisioningException('NameSilo is not set up: enter the API key under Settings → Registrars.');
        }

        try {
            $response = Http::withOptions(['verify' => (bool) ($this->config['verify_tls'] ?? true)])
                ->withMiddleware(ModuleLogger::middleware('namesilo', $operation, [$this->config['api_key'], ...$secrets]))
                ->connectTimeout(30)
                ->timeout($timeout)
                ->get($this->url($operation), [
                    'version' => 1,
                    'type' => 'xml',
                    'key' => $this->config['api_key'],
                    ...array_filter($params, fn ($value) => $value !== null),
                ]);
        } catch (ConnectionException $e) {
            throw new ProvisioningException('Cannot reach NameSilo: '.preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage()), previous: $e);
        }

        if ($response->failed()) {
            throw new ProvisioningException("NameSilo {$operation} failed with HTTP {$response->status()}.");
        }

        try {
            $xml = new SimpleXMLElement($response->body(), LIBXML_NONET);
        } catch (Throwable) {
            throw new ProvisioningException("NameSilo {$operation} returned an unexpected response.");
        }

        $code = trim((string) ($xml->reply->code ?? ''));

        if ($code === '') {
            throw new ProvisioningException("NameSilo {$operation} returned an unexpected response.");
        }

        if (! in_array($code, self::SUCCESS, true)) {
            throw new ProvisioningException('NameSilo: '.(trim((string) $xml->reply->detail) ?: "error {$code}"));
        }

        return $xml->reply;
    }
}

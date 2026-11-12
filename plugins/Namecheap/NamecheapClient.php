<?php

namespace Plugins\Namecheap;

use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Namecheap API: one URL (xml.response), the global parameters ApiUser, ApiKey, UserName, ClientIp and Command, the
 * command's own as more fields. Replies are XML: <ApiResponse Status="OK"> with the result under <CommandResponse>, or
 * Status="ERROR" with the texts in <Errors><Error Number="…">. Namecheap answers only the IP addresses whitelisted under
 * Profile → Tools → API Access, and ClientIp must be one of them.
 */
class NamecheapClient
{
    public const LIVE_URL = 'https://api.namecheap.com/xml.response';

    public const SANDBOX_URL = 'https://api.sandbox.namecheap.com/xml.response';

    /** @param  array{username?: string|null, api_key?: string|null, client_ip?: string|null, sandbox?: bool, verify_tls?: bool}  $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return filled($this->config['username'] ?? null) && filled($this->config['api_key'] ?? null) && filled($this->config['client_ip'] ?? null);
    }

    public function url(): string
    {
        return ($this->config['sandbox'] ?? false) ? self::SANDBOX_URL : self::LIVE_URL;
    }

    /**
     * The <CommandResponse> of a successful call.
     *
     * @param  array<string, scalar|null>  $params
     * @param  list<string|null>  $secrets  values to keep out of the module log (an EPP code)
     */
    public function call(string $command, array $params = [], array $secrets = [], int $timeout = 60): SimpleXMLElement
    {
        if (! $this->configured()) {
            throw new ProvisioningException('Namecheap is not set up: enter the username, API key and IP address under Settings → Registrars.');
        }

        try {
            $response = Http::asForm()
                ->withOptions(['verify' => (bool) ($this->config['verify_tls'] ?? true)])
                ->withMiddleware(ModuleLogger::middleware('namecheap', $command, [$this->config['api_key'], ...$secrets]))
                ->connectTimeout(30)
                ->timeout($timeout)
                ->post($this->url(), [
                    'ApiUser' => $this->config['username'],
                    'ApiKey' => $this->config['api_key'],
                    'UserName' => $this->config['username'],
                    'ClientIp' => $this->config['client_ip'],
                    'Command' => $command,
                    ...array_filter($params, fn ($value) => $value !== null),
                ]);
        } catch (ConnectionException $e) {
            throw new ProvisioningException('Cannot reach Namecheap: '.preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage()), previous: $e);
        }

        if ($response->failed()) {
            throw new ProvisioningException("Namecheap {$command} failed with HTTP {$response->status()}.");
        }

        try {
            $xml = new SimpleXMLElement($response->body(), LIBXML_NONET);
        } catch (Throwable) {
            throw new ProvisioningException("Namecheap {$command} returned an unexpected response.");
        }

        $status = strtoupper((string) ($xml['Status'] ?? ''));

        if ($status === 'ERROR') {
            throw new ProvisioningException('Namecheap: '.self::errors($xml));
        }

        if ($status !== 'OK' || ! isset($xml->CommandResponse)) {
            throw new ProvisioningException("Namecheap {$command} returned an unexpected response.");
        }

        return $xml->CommandResponse;
    }

    /** The <Errors> texts, "First, Second". */
    private static function errors(SimpleXMLElement $xml): string
    {
        $errors = [];

        foreach ($xml->Errors->children() ?? [] as $error) {
            if (trim((string) $error) !== '') {
                $errors[] = trim((string) $error);
            }
        }

        return $errors !== [] ? implode(', ', $errors) : 'unknown error';
    }
}

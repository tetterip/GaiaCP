<?php

namespace Plugins\Enom;

use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Enom reseller API: one URL (interface.asp), a form POST with uid, pw, command and responsetype=xml. Replies are
 * XML (<interface-response>) with ErrCount 0 on success, or the errors in <errors><Err1>…</Err1></errors>.
 * Enom answers only the IP addresses whitelisted on the reseller account.
 */
class EnomClient
{
    public const LIVE_URL = 'https://reseller.enom.com/interface.asp';

    public const TEST_URL = 'https://resellertest.enom.com/interface.asp';

    /** @param  array{uid?: string|null, password?: string|null, test_mode?: bool, verify_tls?: bool}  $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return filled($this->config['uid'] ?? null) && filled($this->config['password'] ?? null);
    }

    public function url(): string
    {
        return ($this->config['test_mode'] ?? false) ? self::TEST_URL : self::LIVE_URL;
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @param  list<string|null>  $secrets  values to keep out of the module log (an EPP code)
     */
    public function call(string $command, array $params = [], array $secrets = [], int $timeout = 60): SimpleXMLElement
    {
        if (! $this->configured()) {
            throw new ProvisioningException('Enom is not set up: enter the login ID and API token under Settings → Registrars.');
        }

        try {
            $response = Http::asForm()
                ->withOptions(['verify' => (bool) ($this->config['verify_tls'] ?? true)])
                ->withMiddleware(ModuleLogger::middleware('enom', $command, [$this->config['password'], ...$secrets]))
                ->connectTimeout(30)
                ->timeout($timeout)
                ->post($this->url(), [
                    'uid' => $this->config['uid'],
                    'pw' => $this->config['password'],
                    'command' => $command,
                    'responsetype' => 'xml',
                    ...$params,
                ]);
        } catch (ConnectionException $e) {
            throw new ProvisioningException('Cannot reach Enom: '.preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage()), previous: $e);
        }

        if ($response->failed()) {
            throw new ProvisioningException("Enom {$command} failed with HTTP {$response->status()}.");
        }

        try {
            $xml = new SimpleXMLElement($response->body(), LIBXML_NONET);
        } catch (Throwable) {
            throw new ProvisioningException("Enom {$command} returned an unexpected response.");
        }

        if (! isset($xml->ErrCount)) {
            throw new ProvisioningException("Enom {$command} returned an unexpected response.");
        }

        if ((int) $xml->ErrCount > 0) {
            throw new ProvisioningException('Enom: '.self::errors($xml));
        }

        return $xml;
    }

    /** The <errors> texts, "Err1, Err2". */
    private static function errors(SimpleXMLElement $xml): string
    {
        $errors = [];

        foreach ($xml->errors->children() ?? [] as $error) {
            if (trim((string) $error) !== '') {
                $errors[] = trim((string) $error);
            }
        }

        return $errors !== [] ? implode(', ', $errors) : 'unknown error';
    }
}

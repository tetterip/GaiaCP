<?php

namespace Plugins\EasyGr;

use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * easy.gr reseller API: one HTTPS endpoint, form POST with username, password
 * and a numeric "action". Replies are JSON with "done": 1 on success, 0 with
 * an "errors" map on failure, 3 when the .gr registry is unreachable.
 *
 * Unlike easy.gr's WHMCS module, TLS certificates are verified unless the
 * registrar's "Verify TLS certificate" is off. Calls go over
 * IPv4 like the module does, since the account whitelists IPv4 addresses.
 */
class EasyClient
{
    /** Action numbers → names, for the module log. */
    public const ACTIONS = [0 => 'availability', 1 => 'EPP code', 2 => 'get nameservers', 3 => 'save nameservers', 4 => 'register', 5 => 'renew', 11 => 'transfer', 19 => 'sync'];

    /** @param  array{url?: string|null, username?: string|null, password?: string|null, verify_tls?: bool}  $config */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return filled($this->config['username'] ?? null) && filled($this->config['password'] ?? null);
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed> the whole decoded reply
     */
    public function call(int $action, array $params = [], int $timeout = 60): array
    {
        if (! $this->configured()) {
            throw new ProvisioningException('easy.gr is not set up: enter the username and password under Settings → Registrars.');
        }

        $response = $this->send('post', $action, $params, $timeout);

        if ($response->failed()) {
            throw new ProvisioningException("easy.gr action {$action} failed with HTTP {$response->status()}".self::failureReason($response->json(), $response->body()).'.');
        }

        $json = $response->json();

        if (! is_array($json) || ! array_key_exists('done', $json)) {
            throw new ProvisioningException("easy.gr action {$action} returned an unexpected response.");
        }

        return match ((int) $json['done']) {
            1 => $json,
            3 => throw new ProvisioningException('easy.gr cannot reach the .gr registry right now; try again later.'),
            default => throw new ProvisioningException('easy.gr: '.self::errors($json['errors'] ?? null).self::hint($json['errors'] ?? null, $params)),
        };
    }

    /**
     * Whether a domain can be registered: action 0, asked as easy.gr's whoisservers.php for WHMCS does (a GET whose
     * reply says "not exist" when the domain is free).
     */
    public function available(string $domain): bool
    {
        if (! $this->configured()) {
            throw new ProvisioningException('easy.gr is not set up: enter the username and password under Settings → Registrars.');
        }

        $response = $this->send('get', EasyRegistrar::AVAILABILITY, ['domain' => $domain], 15);
        $json = $response->json();

        if ($response->failed() || (is_array($json) && (int) ($json['done'] ?? 1) !== 1)) {
            throw new ProvisioningException('easy.gr could not check '.$domain.(is_array($json) && filled($json['errors'] ?? null) ? ': '.self::errors($json['errors']) : self::failureReason($json, $response->body())).'.');
        }

        if (trim($response->body()) === '') {
            throw new ProvisioningException("easy.gr could not check {$domain} (empty reply).");
        }

        return str_contains(mb_strtolower($response->body()), 'not exist');
    }

    /** @param  array<string, scalar|null>  $params */
    private function send(string $method, int $action, array $params, int $timeout): Response
    {
        $url = $this->config['url'] ?? 'https://api.easy.gr';
        $fields = ['username' => $this->config['username'], 'password' => $this->config['password'], 'action' => $action, ...$params];

        try {
            return Http::asForm()
                ->acceptJson()
                ->withOptions(['force_ip_resolve' => 'v4', 'verify' => (bool) ($this->config['verify_tls'] ?? true)])
                ->withMiddleware(ModuleLogger::middleware(
                    'easy',
                    trim($action.' '.(self::ACTIONS[$action] ?? '')),
                    [$this->config['password'], ...ModuleLogger::secretsIn($params)],
                    $action === 1 ? ['response'] : [], // the EPP code
                ))
                ->connectTimeout($action === 0 ? 5 : 30)
                ->timeout($timeout)
                ->{$method}($url, $fields);
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            if (str_contains($reason, 'SSL certificate')) {
                $reason .= '. If https://api.easy.gr shows a valid certificate in a browser, this server\'s CA certificates are out of date; otherwise ask easy.gr to renew theirs. Until then you can turn off "Verify TLS certificate" on the registrar.';
            }

            throw new ProvisioningException("Cannot reach easy.gr: {$reason}", previous: $e);
        }
    }

    /**
     * What easy.gr said along with an HTTP error: its "errors" when the body is its usual JSON, otherwise the
     * start of the text (an error page), so the admin need not open the module log to see why.
     */
    private static function failureReason(mixed $json, string $body): string
    {
        if (is_array($json) && filled($json['errors'] ?? null)) {
            return ': '.self::errors($json['errors']);
        }

        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $body)))));

        return $text === '' ? ' (empty reply)' : ': '.mb_strimwidth($text, 0, 200, '…');
    }

    /** What 2303 (EPP "Object does not exist") means for a domain: it is not in this account, e.g. registered elsewhere. */
    private static function hint(mixed $errors, array $params): string
    {
        return is_array($errors) && array_key_exists(2303, $errors) && filled($params['domain'] ?? null)
            ? " ({$params['domain']} is not in this easy.gr account)"
            : '';
    }

    /** "code - message, code - message", as the WHMCS module shows them. */
    private static function errors(mixed $errors): string
    {
        if (! is_array($errors) || $errors === []) {
            return is_string($errors) && $errors !== '' ? $errors : 'unknown error';
        }

        // Error codes are numeric keys ("2303": "…"), so only a plain JSON list has no codes.
        $list = array_is_list($errors);
        $parts = [];

        foreach ($errors as $key => $message) {
            $message = is_scalar($message) ? (string) $message : json_encode($message, JSON_UNESCAPED_UNICODE);
            $parts[] = $list ? $message : "{$key} - {$message}";
        }

        return implode(', ', $parts);
    }
}

<?php

namespace App\Support;

use App\Models\ModuleLog;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * WHMCS-style module log: a Guzzle middleware that the API clients (WHM, Plesk, easy.gr, ΑΑΔΕ) add to their
 * requests, so every request and its response or error is kept when Settings → Logging → Module log is on.
 *
 * Secrets never reach the log: the values the caller passes (API tokens, passwords, EPP codes) are replaced
 * wherever they appear, in plain, URL-encoded and XML-escaped form, and so are the values of auth headers and of
 * form fields, JSON keys and XML elements with a password-like name. Logging never breaks the call it watches.
 */
class ModuleLogger
{
    public const MASK = '********';

    /** Field / element / JSON key names whose values are always masked. */
    private const SECRET_NAMES = 'password|passwd|pass|pw|api_token|token|secret|epp|epp_code|auth_code';

    /** Headers whose values are always masked. */
    private const SECRET_HEADERS = ['authorization', 'http_auth_passwd', 'key', 'cookie', 'set-cookie'];

    /** Longest request or response body kept, in bytes. */
    private const MAX_BODY = 65536;

    public static function enabled(): bool
    {
        return (bool) config('gaiacp.debug.module_log');
    }

    /**
     * @param  string  $module  a ModuleLog::modules() key (from the plugin's moduleLogs())
     * @param  list<string|null>  $secrets  values to mask wherever they appear
     * @param  list<string>  $secretResponseKeys  JSON keys of the response to mask too (e.g. easy.gr's EPP reply)
     */
    public static function middleware(string $module, string $action, array $secrets = [], array $secretResponseKeys = []): callable
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler, $module, $action, $secrets, $secretResponseKeys): PromiseInterface {
            if (! self::enabled()) {
                return $handler($request, $options);
            }

            $started = hrtime(true);
            $write = function (?ResponseInterface $response, ?Throwable $error) use ($request, $module, $action, $secrets, $secretResponseKeys, $started): void {
                rescue(fn () => self::write($module, $action, $request, $response, $error, $secrets, $secretResponseKeys, (int) round((hrtime(true) - $started) / 1e6)), report: false);
            };

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                $write(null, $e); // a handler that throws instead of rejecting

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($write) {
                    $write($response, null);

                    return $response;
                },
                function ($reason) use ($write) {
                    $write(null, $reason instanceof Throwable ? $reason : null);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Secret values in a request's parameters: fields with a password-like name, and Plesk-style
     * ['name' => 'ftp_password', 'value' => …] pairs.
     *
     * @param  array<array-key, mixed>  $params
     * @return list<string>
     */
    public static function secretsIn(array $params): array
    {
        $found = [];
        $secretName = fn (mixed $name) => is_string($name) && preg_match('/^(?:'.self::SECRET_NAMES.')$|pass/i', $name) === 1;

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                if ($secretName($value['name'] ?? null) && is_scalar($value['value'] ?? null)) {
                    $found[] = (string) $value['value'];
                }
                $found = [...$found, ...self::secretsIn($value)];
            } elseif (is_scalar($value) && $secretName($key)) {
                $found[] = (string) $value;
            }
        }

        return $found;
    }

    /** @param  list<string|null>  $secrets */
    private static function write(string $module, string $action, RequestInterface $request, ?ResponseInterface $response, ?Throwable $error, array $secrets, array $secretResponseKeys, int $ms): void
    {
        $secrets = array_values(array_filter(array_map('strval', array_filter($secrets, fn ($s) => $s !== null)), fn (string $s) => $s !== ''));

        $requestText = self::headers($request)."\n\n".self::body($request);
        $responseText = $response ? self::headers($response)."\n\n".self::body($response) : null;

        if ($responseText !== null && $secretResponseKeys !== []) {
            $responseText = self::maskJsonKeys($responseText, $secretResponseKeys);
        }

        ModuleLog::create([
            'module' => $module,
            'action' => $action,
            'url' => mb_substr(self::mask($request->getMethod().' '.$request->getUri(), $secrets), 0, 2048),
            'request' => self::mask($requestText, $secrets),
            'status' => $response?->getStatusCode(),
            'response' => $responseText !== null ? self::mask($responseText, $secrets) : null,
            'error' => $error ? self::mask(preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $error->getMessage()), $secrets) : null,
            'duration_ms' => $ms,
        ]);
    }

    private static function headers(MessageInterface $message): string
    {
        $lines = $message instanceof ResponseInterface ? ["HTTP {$message->getStatusCode()} {$message->getReasonPhrase()}"] : [];

        foreach ($message->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), self::SECRET_HEADERS, true)) {
                $values = [self::MASK];
            }
            $lines[] = $name.': '.implode(', ', $values);
        }

        return implode("\n", $lines);
    }

    private static function body(MessageInterface $message): string
    {
        $stream = $message->getBody();

        if (! $stream->isSeekable()) {
            return '(body not readable)';
        }

        $stream->rewind();
        $body = $stream->read(self::MAX_BODY + 1);
        $stream->rewind(); // the client still has to read it

        if (! mb_check_encoding($body, 'UTF-8')) {
            return '('.$stream->getSize().' bytes of binary data)'; // e.g. an encrypted push
        }

        return strlen($body) > self::MAX_BODY ? mb_strcut($body, 0, self::MAX_BODY)."\n… (cut at 64 KB)" : $body;
    }

    /**
     * Masks the known secrets, then anything that looks like one by its name.
     *
     * @param  list<string>  $secrets
     */
    public static function mask(string $text, array $secrets = []): string
    {
        $forms = [];
        foreach ($secrets as $secret) {
            $forms[] = $secret;
            $forms[] = urlencode($secret);
            $forms[] = rawurlencode($secret);
            $forms[] = htmlspecialchars($secret, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $forms[] = trim((string) json_encode($secret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '"');
        }
        $forms = array_unique(array_filter($forms, fn (string $f) => $f !== ''));
        usort($forms, fn (string $a, string $b) => strlen($b) <=> strlen($a)); // longest first
        $text = str_replace($forms, self::MASK, $text);

        $names = self::SECRET_NAMES;

        // Form fields and query strings: password=…&
        $text = preg_replace('/(?<=^|[?&\n])((?:'.$names.')=)[^&\s]*/i', '$1'.self::MASK, $text);
        // XML elements: <passwd>…</passwd>, <ns1:Password>…</ns1:Password>
        $text = preg_replace('/(<((?:[\w-]+:)?(?:'.$names.'))(?:\s[^>]*)?>)[^<]*(<\/\2>)/i', '$1'.self::MASK.'$3', $text);
        // JSON: "password": "…"
        $text = preg_replace('/("(?:'.$names.')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"'.self::MASK.'"', $text);

        return $text;
    }

    /** @param  list<string>  $keys */
    private static function maskJsonKeys(string $text, array $keys): string
    {
        $names = implode('|', array_map(fn (string $k) => preg_quote($k, '/'), $keys));

        return preg_replace('/("(?:'.$names.')"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|[^,}\]\s]+)/', '$1"'.self::MASK.'"', $text);
    }
}

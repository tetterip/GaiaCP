<?php

namespace Plugins\Plesk;

use App\Models\Server;
use App\Provisioning\ProvisioningException;
use App\Support\ModuleLogger;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Minimal Plesk XML API client: POST https://host:8443/enterprise/control/agent.php
 * with a <packet>. Authenticates with the admin login and password, or with a
 * secret key ("plesk bin secret_key -c") when the server's username is empty.
 *
 * Request bodies are ordered arrays, because the XML schema is order-sensitive:
 *   ['filter' => ['name' => 'example.com']]       -> <filter><name>example.com</name></filter>
 *   ['filter' => []]                              -> <filter/>
 *   ['property' => [['name' => 'a'], ['name' => 'b']]] -> two <property> elements
 *   null values are left out.
 */
class PleskClient
{
    /** Plesk's "object does not exist" error. */
    public const ERR_NOT_FOUND = 1013;

    public function __construct(private Server $server) {}

    /**
     * Runs one operation and returns its <result> elements (several for a
     * "get" that matches several objects).
     *
     * @param  array<string, mixed>  $body
     * @return list<SimpleXMLElement>
     */
    public function call(string $operator, string $operation, array $body = [], bool $allowNotFound = false, int $timeout = 30): array
    {
        $xml = $this->send($this->packet($operator, $operation, $body), $timeout, "{$operator} {$operation}", ModuleLogger::secretsIn($body));

        if (isset($xml->system)) {
            throw new ProvisioningException('Plesk: '.$this->errorText($xml->system));
        }

        $results = [];

        foreach ($xml->{$operator}->{$operation}->result ?? [] as $result) {
            if ((string) $result->status === 'ok') {
                $results[] = $result;

                continue;
            }

            if ($allowNotFound && (int) $result->errcode === self::ERR_NOT_FOUND) {
                continue;
            }

            throw new ProvisioningException("Plesk {$operator} {$operation}: ".$this->errorText($result));
        }

        return $results;
    }

    /** @param  array<string, mixed>  $body */
    public function packet(string $operator, string $operation, array $body): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $packet = $doc->appendChild($doc->createElement('packet'));
        $op = $packet->appendChild($doc->createElement($operator))->appendChild($doc->createElement($operation));
        $this->append($doc, $op, $body);

        return $doc->saveXML();
    }

    /** @param  list<string>  $secrets  passwords in the packet, masked in the module log */
    private function send(string $packet, int $timeout, string $action, array $secrets): SimpleXMLElement
    {
        $headers = filled($this->server->username)
            ? ['HTTP_AUTH_LOGIN' => $this->server->username, 'HTTP_AUTH_PASSWD' => (string) $this->server->api_token]
            : ['KEY' => (string) $this->server->api_token];

        try {
            $response = Http::withHeaders($headers)
                ->withOptions(['verify' => $this->server->verify_tls])
                ->withMiddleware(ModuleLogger::middleware('plesk', $action, [$this->server->api_token, ...$secrets]))
                ->withBody($packet, 'text/xml')
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post(sprintf('https://%s:%d/enterprise/control/agent.php', $this->server->hostname, $this->server->port));
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ProvisioningException("Cannot reach {$this->server->hostname}:{$this->server->port}: {$reason}", previous: $e);
        }

        if ($response->failed()) {
            throw new ProvisioningException("Plesk returned HTTP {$response->status()}.");
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        libxml_use_internal_errors($previous);

        if ($xml === false || $xml->getName() !== 'packet') {
            throw new ProvisioningException('Plesk returned an unexpected response.');
        }

        return $xml;
    }

    /** @param  array<string, mixed>  $values */
    private function append(DOMDocument $doc, DOMElement $parent, array $values): void
    {
        foreach ($values as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && $value !== [] && array_is_list($value) && is_array($value[0])) {
                foreach ($value as $item) {
                    $this->append($doc, $parent->appendChild($doc->createElement($name)), $item);
                }

                continue;
            }

            $element = $parent->appendChild($doc->createElement($name));

            if (is_array($value)) {
                $this->append($doc, $element, $value);
            } else {
                $element->appendChild($doc->createTextNode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
            }
        }
    }

    private function errorText(SimpleXMLElement $node): string
    {
        return trim((string) $node->errtext).' (error '.(int) $node->errcode.')';
    }
}

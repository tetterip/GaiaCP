<?php

namespace Tests;

use App\Models\Registrar;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\NameSilo\NameSiloClient;

/**
 * A tiny in-memory NameSilo account: keeps domains (expiry, nameservers, contact IDs, lock) and contact records, and
 * answers the operations GaiaCP uses with the API's XML. $namesiloCalls records [operation, params] pairs without the key.
 */
trait FakesNameSilo
{
    /** @var array<string, array<string, mixed>> */
    protected array $namesiloDomains = [];

    /** @var array<string, array<string, string>> contact ID => fields */
    protected array $namesiloContacts = [];

    /** @var list<array{0: string, 1: array<string, string>}> */
    protected array $namesiloCalls = [];

    /** @var array<string, string> operation => error text */
    protected array $namesiloFailures = [];

    /** @var array<string, string> child nameserver label => IP */
    protected array $namesiloHosts = [];

    /** @var array<string, array<string, string>> tld => [registration, renew, transfer] */
    protected array $namesiloPrices = [];

    protected Registrar $namesiloRegistrar;

    protected function fakeNameSilo(): void
    {
        $this->namesiloRegistrar = Registrar::factory()->withTlds(['.com', '.net'])->create([
            'name' => 'NameSilo', 'module' => 'namesilo', 'credentials' => ['api_key' => 'secret'],
        ]);

        Http::fake(function (Request $request) {
            $url = strtok($request->url(), '?');
            $base = str_starts_with($url, NameSiloClient::SANDBOX_URL) ? NameSiloClient::SANDBOX_URL : NameSiloClient::LIVE_URL;

            if (! str_starts_with($url, $base)) {
                return Http::response('Not found', 404);
            }

            $operation = substr($url, strlen($base));
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
            unset($params['version'], $params['type'], $params['key']);
            $this->namesiloCalls[] = [$operation, $params];

            if (isset($this->namesiloFailures[$operation])) {
                return $this->namesiloReply($operation, '', '110', $this->namesiloFailures[$operation]);
            }

            $domain = $params['domain'] ?? '';
            $known = isset($this->namesiloDomains[$domain]);
            $missing = $this->namesiloReply($operation, '', '200', 'Domain is not active, or does not belong to this user');

            switch ($operation) {
                case 'checkRegisterAvailability':
                    $name = $params['domains'];
                    if (isset($this->namesiloDomains[$name])) {
                        return $this->namesiloReply($operation, "<unavailable><domain>{$name}</domain></unavailable>");
                    }

                    return $this->namesiloReply($operation, '<available><domain price="8.99" premium="'.(str_starts_with($name, 'premium.') ? 1 : 0).'" duration="1">'.$name.'</domain></available>');
                case 'getDomainInfo':
                    if (! $known) {
                        return $missing;
                    }
                    $d = $this->namesiloDomains[$domain];
                    $ns = implode('', array_map(fn (string $n, int $i) => '<nameserver position="'.($i + 1).'">'.$n.'</nameserver>', $d['ns'] ?? [], array_keys($d['ns'] ?? [])));
                    $ids = implode('', array_map(fn (string $role, string $id) => "<{$role}>{$id}</{$role}>", array_keys($d['contacts'] ?? []), $d['contacts'] ?? []));

                    return $this->namesiloReply($operation, '<created>2020-01-01</created><expires>'.$d['expires'].'</expires><status>Active</status>'
                        .'<locked>'.(($d['locked'] ?? true) ? 'Yes' : 'No').'</locked><private>Yes</private><auto_renew>No</auto_renew>'
                        ."<nameservers>{$ns}</nameservers><contact_ids>{$ids}</contact_ids>");
                case 'registerDomain':
                    $this->namesiloDomains[$domain] = [
                        'expires' => now()->addYears((int) $params['years'])->toDateString(),
                        'ns' => array_values(array_filter(array_map(fn (int $i) => $params["ns{$i}"] ?? null, range(1, 13)))),
                    ];

                    return $this->namesiloReply($operation, '<message>Your domain registration was successfully processed.</message><domain>'.$domain.'</domain><order_amount>8.99</order_amount>');
                case 'transferDomain':
                    return $this->namesiloReply($operation, '<message>Your transfer request was successfully processed.</message><domain>'.$domain.'</domain>');
                case 'renewDomain':
                    if (! $known) {
                        return $missing;
                    }
                    $this->namesiloDomains[$domain]['expires'] = now()->parse($this->namesiloDomains[$domain]['expires'])->addYears((int) $params['years'])->toDateString();

                    return $this->namesiloReply($operation, '<domain>'.$domain.'</domain><order_amount>8.99</order_amount>');
                case 'changeNameServers':
                    $this->namesiloDomains[$domain]['ns'] = array_values(array_filter(array_map(fn (int $i) => $params["ns{$i}"] ?? null, range(1, 13))));

                    return $this->namesiloReply($operation);
                case 'retrieveAuthCode':
                    return $known ? $this->namesiloReply($operation) : $missing;
                case 'contactList':
                    $c = $this->namesiloContacts[$params['contact_id']] ?? null;
                    if ($c === null) {
                        return $this->namesiloReply($operation, '', '210', 'Invalid contact ID');
                    }

                    return $this->namesiloReply($operation, '<contact><contact_id>'.$params['contact_id'].'</contact_id>'
                        .implode('', array_map(fn (string $k, string $v) => "<{$k}>".htmlspecialchars($v, ENT_XML1)."</{$k}>", array_keys($c), $c)).'</contact>');
                case 'contactAdd':
                    $id = (string) (count($this->namesiloContacts) + 100);
                    $this->namesiloContacts[$id] = ['first_name' => $params['fn'], 'last_name' => $params['ln'], 'company' => $params['nn'] ?? '', 'address' => $params['ad'],
                        'city' => $params['cy'], 'state' => $params['st'], 'zip' => $params['zp'], 'country' => $params['ct'], 'email' => $params['em'], 'phone' => $params['ph']];

                    return $this->namesiloReply($operation, "<contact_id>{$id}</contact_id>");
                case 'contactDomainAssociate':
                    foreach (['registrant', 'administrative', 'technical', 'billing'] as $role) {
                        if (isset($params[$role])) {
                            $this->namesiloDomains[$domain]['contacts'][$role] = $params[$role];
                        }
                    }

                    return $this->namesiloReply($operation);
                case 'addRegisteredNameServer':
                    $this->namesiloHosts[$params['new_host']] = $params['ip1'];

                    return $this->namesiloReply($operation);
                case 'modifyRegisteredNameServer':
                    unset($this->namesiloHosts[$params['current_host']]);
                    $this->namesiloHosts[$params['new_host']] = $params['ip1'];

                    return $this->namesiloReply($operation);
                case 'deleteRegisteredNameServer':
                    unset($this->namesiloHosts[$params['current_host']]);

                    return $this->namesiloReply($operation);
                case 'domainLock':
                case 'domainUnlock':
                    $this->namesiloDomains[$domain]['locked'] = $operation === 'domainLock';

                    return $this->namesiloReply($operation);
                case 'getPrices':
                    return $this->namesiloReply($operation, implode('', array_map(
                        fn (string $tld, array $p) => "<{$tld}>".implode('', array_map(fn (string $k, string $v) => "<{$k}>{$v}</{$k}>", array_keys($p), $p))."</{$tld}>",
                        array_keys($this->namesiloPrices), $this->namesiloPrices,
                    )));
            }

            return $this->namesiloReply($operation, '', '101', 'Invalid operation');
        });
    }

    private function namesiloReply(string $operation, string $body = '', string $code = '300', string $detail = 'success'): PromiseInterface
    {
        return Http::response('<?xml version="1.0"?><namesilo><request><operation>'.$operation.'</operation><ip>192.0.2.1</ip></request>'
            ."<reply><code>{$code}</code><detail>".htmlspecialchars($detail, ENT_XML1)."</detail>{$body}</reply></namesilo>", 200, ['Content-Type' => 'text/xml']);
    }

    /** @return list<string> the operations called, in order */
    protected function namesiloOperations(): array
    {
        return array_column($this->namesiloCalls, 0);
    }
}

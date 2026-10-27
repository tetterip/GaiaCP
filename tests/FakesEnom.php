<?php

namespace Tests;

use App\Models\Registrar;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Enom\EnomClient;

/**
 * A tiny in-memory Enom reseller account: keeps domains (expiry, nameservers) and answers the commands GaiaCP uses
 * with interface.asp's XML. $enomCalls records [command, params] pairs without the login.
 */
trait FakesEnom
{
    /** @var array<string, array{expires: string, ns?: list<string>}> */
    protected array $enomDomains = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    protected array $enomCalls = [];

    /** @var array<string, string> command => error text to answer with */
    protected array $enomFailures = [];

    /** The registrar account the fake answers for; sells .com and .net. */
    protected Registrar $enomRegistrar;

    /** @var array<string, string> private nameserver host => IP */
    protected array $enomHosts = [];

    protected function fakeEnom(): void
    {
        $this->enomRegistrar = Registrar::factory()->withTlds(['.com', '.net'])->create([
            'name' => 'Enom', 'module' => 'enom', 'credentials' => ['uid' => 'reseller', 'password' => 'secret'],
        ]);

        Http::fake(function (Request $request) {
            if (! in_array($request->url(), [EnomClient::LIVE_URL, EnomClient::TEST_URL], true)) {
                return Http::response('Not found', 404);
            }

            parse_str($request->body(), $params);
            $command = $params['command'];
            unset($params['uid'], $params['pw'], $params['command'], $params['responsetype']);
            $this->enomCalls[] = [$command, $params];

            if (isset($this->enomFailures[$command])) {
                return $this->enomReply($command, '', [$this->enomFailures[$command]]);
            }

            $domain = isset($params['SLD']) ? $params['SLD'].'.'.$params['TLD'] : '';
            $missing = $this->enomReply($command, '', ['Domain name not found']);
            $ns = fn (array $p) => array_values(array_filter(array_map(fn (int $i) => $p["NS{$i}"] ?? null, range(1, 12))));

            switch ($command) {
                case 'Check': // free unless the account holds it
                    return isset($this->enomDomains[$domain])
                        ? $this->enomReply($command, '<DomainName>'.$domain.'</DomainName><RRPCode>211</RRPCode><RRPText>Domain not available</RRPText>')
                        : $this->enomReply($command, '<DomainName>'.$domain.'</DomainName><RRPCode>210</RRPCode><RRPText>Domain available</RRPText>');
                case 'GetDomainInfo':
                    return isset($this->enomDomains[$domain])
                        ? $this->enomReply($command, '<GetDomainInfo><domainname>'.$domain.'</domainname><status><expiration>'
                            .now()->parse($this->enomDomains[$domain]['expires'])->format('n/j/Y').' 12:00:00 AM</expiration></status></GetDomainInfo>')
                        : $missing;
                case 'Purchase':
                    $this->enomDomains[$domain] = [
                        'expires' => now()->addYears((int) $params['NumYears'])->toDateString(),
                        'ns' => $ns($params) ?: ['dns1.name-services.com', 'dns2.name-services.com'],
                    ];

                    return $this->enomReply($command, '<RRPCode>200</RRPCode><RRPText>Command completed successfully</RRPText><OrderID>1</OrderID>');
                case 'TP_CreateOrder':
                    return $this->enomReply($command, '<transferorder><transferorderid>1</transferorderid></transferorder>');
                case 'Extend':
                    if (! isset($this->enomDomains[$domain])) {
                        return $missing;
                    }
                    $this->enomDomains[$domain]['expires'] = now()->parse($this->enomDomains[$domain]['expires'])->addYears((int) $params['NumYears'])->toDateString();

                    return $this->enomReply($command, '<Extension>successful</Extension>');
                case 'GetDNS':
                    return isset($this->enomDomains[$domain])
                        ? $this->enomReply($command, implode('', array_map(fn (string $n) => "<dns>{$n}</dns>", $this->enomDomains[$domain]['ns'] ?? [])).'<UseDNS>custom</UseDNS>')
                        : $missing;
                case 'ModifyNS':
                    if (! isset($this->enomDomains[$domain])) {
                        return $missing;
                    }
                    $this->enomDomains[$domain]['ns'] = $ns($params);

                    return $this->enomReply($command, '<RRPCode>200</RRPCode>');
                case 'GetContacts':
                    if (! isset($this->enomDomains[$domain])) {
                        return $missing;
                    }
                    $contact = fn (string $prefix, string $first) => "<{$prefix}><{$prefix}FirstName>{$first}</{$prefix}FirstName><{$prefix}LastName>Smith</{$prefix}LastName>"
                        ."<{$prefix}OrganizationName>Acme Inc</{$prefix}OrganizationName><{$prefix}Address1>1 Main St</{$prefix}Address1><{$prefix}City>Boston</{$prefix}City>"
                        ."<{$prefix}StateProvince>MA</{$prefix}StateProvince><{$prefix}PostalCode>02101</{$prefix}PostalCode><{$prefix}Country>US</{$prefix}Country>"
                        ."<{$prefix}Phone>+1.6175551234</{$prefix}Phone><{$prefix}EmailAddress>john@acme.com</{$prefix}EmailAddress></{$prefix}>";

                    return $this->enomReply($command, '<GetContacts>'.$contact('Registrant', 'John').$contact('Admin', 'Jane').$contact('Tech', 'Tom').$contact('AuxBilling', 'Bill').'</GetContacts>');
                case 'Contacts':
                    $this->enomDomains[$domain]['contacts'][$params['ContactType']] = $params;

                    return $this->enomReply($command, '<RRPCode>200</RRPCode>');
                case 'RegisterNameServer':
                    $this->enomHosts[$params['NSName']] = $params['IP'];

                    return $this->enomReply($command, '<RegisterNameserver><NsSuccess>1</NsSuccess></RegisterNameserver>');
                case 'UpdateNameServer':
                    $this->enomHosts[$params['NS']] = $params['NewIP'];

                    return $this->enomReply($command, '<NsSuccess>1</NsSuccess>');
                case 'DeleteNameServer':
                    unset($this->enomHosts[$params['NS']]);

                    return $this->enomReply($command, '<NsSuccess>1</NsSuccess>');
                case 'GetRegLock':
                    return $this->enomReply($command, '<reg-lock>'.(($this->enomDomains[$domain]['locked'] ?? true) ? 1 : 0).'</reg-lock>');
                case 'SetRegLock':
                    $this->enomDomains[$domain]['locked'] = (int) $params['UnlockRegistrar'] === 0;

                    return $this->enomReply($command, '<RRPCode>200</RRPCode>');
                case 'SynchAuthInfo':
                    return isset($this->enomDomains[$domain]) ? $this->enomReply($command, '<InfoSynched>True</InfoSynched><EPPEmailMessage>Sent</EPPEmailMessage>') : $missing;
            }

            return $this->enomReply($command, '', ["Unknown command {$command}"]);
        });
    }

    /** @param  list<string>  $errors */
    private function enomReply(string $command, string $body, array $errors = []): PromiseInterface
    {
        $errorXml = $errors === [] ? '' : '<errors>'.implode('', array_map(fn (string $e, int $i) => '<Err'.($i + 1).'>'.htmlspecialchars($e, ENT_XML1).'</Err'.($i + 1).'>', $errors, array_keys($errors))).'</errors>';

        return Http::response('<?xml version="1.0" encoding="utf-8"?><interface-response>'.$body.'<Command>'.strtoupper($command).'</Command>'
            .'<ErrCount>'.count($errors).'</ErrCount>'.$errorXml.'<Done>true</Done></interface-response>', 200, ['Content-Type' => 'text/xml']);
    }

    /** @return list<string> the commands called, in order */
    protected function enomCommands(): array
    {
        return array_column($this->enomCalls, 0);
    }
}

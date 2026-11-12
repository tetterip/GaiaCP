<?php

namespace Tests;

use App\Models\Registrar;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Namecheap\NamecheapClient;

/**
 * A tiny in-memory Namecheap account: keeps domains (expiry, nameservers, contacts, lock) and answers the commands
 * GaiaCP uses with xml.response's XML. $namecheapCalls records [command, params] pairs without the login.
 */
trait FakesNamecheap
{
    /** @var array<string, array<string, mixed>> */
    protected array $namecheapDomains = [];

    /** @var list<array{0: string, 1: array<string, string>}> */
    protected array $namecheapCalls = [];

    /** @var array<string, string> command => error text */
    protected array $namecheapFailures = [];

    /** @var array<string, string> child nameserver host => IP */
    protected array $namecheapHosts = [];

    /** @var array<string, array<string, string>> tld => [register, renew, transfer] */
    protected array $namecheapPrices = [];

    protected Registrar $namecheapRegistrar;

    protected function fakeNamecheap(): void
    {
        $this->namecheapRegistrar = Registrar::factory()->withTlds(['.com', '.net'])->create([
            'name' => 'Namecheap', 'module' => 'namecheap', 'credentials' => ['username' => 'reseller', 'api_key' => 'secret', 'client_ip' => '192.0.2.1'],
        ]);

        Http::fake(function (Request $request) {
            if (! in_array($request->url(), [NamecheapClient::LIVE_URL, NamecheapClient::SANDBOX_URL], true)) {
                return Http::response('Not found', 404);
            }

            parse_str($request->body(), $params);
            $command = $params['Command'];
            unset($params['ApiUser'], $params['ApiKey'], $params['UserName'], $params['ClientIp'], $params['Command']);
            $this->namecheapCalls[] = [$command, $params];

            if (isset($this->namecheapFailures[$command])) {
                return $this->namecheapReply($command, '', $this->namecheapFailures[$command]);
            }

            $domain = $params['DomainName'] ?? $params['DomainList'] ?? (isset($params['SLD']) ? $params['SLD'].'.'.$params['TLD'] : '');
            $missing = $this->namecheapReply($command, '', "Domain {$domain} not found");
            $known = isset($this->namecheapDomains[$domain]);

            switch ($command) {
                case 'namecheap.domains.check':
                    return $this->namecheapReply($command, '<DomainCheckResult Domain="'.$params['DomainList'].'" Available="'.($known ? 'false' : 'true').'" IsPremiumName="'
                        .(str_starts_with($params['DomainList'], 'premium.') ? 'true' : 'false').'" />');
                case 'namecheap.domains.getInfo':
                    return $known
                        ? $this->namecheapReply($command, '<DomainGetInfoResult Status="Ok" DomainName="'.$domain.'"><DomainDetails><CreatedDate>01/01/2020</CreatedDate><ExpiredDate>'
                            .now()->parse($this->namecheapDomains[$domain]['expires'])->format('m/d/Y').'</ExpiredDate></DomainDetails></DomainGetInfoResult>')
                        : $missing;
                case 'namecheap.domains.create':
                    $this->namecheapDomains[$domain] = [
                        'expires' => now()->addYears((int) $params['Years'])->toDateString(),
                        'ns' => isset($params['Nameservers']) ? explode(',', $params['Nameservers']) : ['dns1.registrar-servers.com', 'dns2.registrar-servers.com'],
                    ];

                    return $this->namecheapReply($command, '<DomainCreateResult Domain="'.$domain.'" Registered="true" ChargedAmount="9.06" DomainID="1" />');
                case 'namecheap.domains.transfer.create':
                    return $this->namecheapReply($command, '<DomainTransferCreateResult DomainName="'.$domain.'" Transfer="true" TransferID="1" StatusID="-1" />');
                case 'namecheap.domains.renew':
                    if (! $known) {
                        return $missing;
                    }
                    $this->namecheapDomains[$domain]['expires'] = now()->parse($this->namecheapDomains[$domain]['expires'])->addYears((int) $params['Years'])->toDateString();

                    return $this->namecheapReply($command, '<DomainRenewResult DomainName="'.$domain.'" DomainID="1" Renew="true" ChargedAmount="9.06" />');
                case 'namecheap.domains.dns.getList':
                    return $known
                        ? $this->namecheapReply($command, '<DomainDNSGetListResult Domain="'.$domain.'" IsUsingOurDNS="false">'
                            .implode('', array_map(fn (string $n) => "<Nameserver>{$n}</Nameserver>", $this->namecheapDomains[$domain]['ns'] ?? [])).'</DomainDNSGetListResult>')
                        : $missing;
                case 'namecheap.domains.dns.setCustom':
                    $this->namecheapDomains[$domain]['ns'] = explode(',', $params['Nameservers']);

                    return $this->namecheapReply($command, '<DomainDNSSetCustomResult Domain="'.$domain.'" Updated="true" />');
                case 'namecheap.domains.dns.setDefault':
                    $this->namecheapDomains[$domain]['ns'] = ['dns1.registrar-servers.com', 'dns2.registrar-servers.com'];

                    return $this->namecheapReply($command, '<DomainDNSSetDefaultResult Domain="'.$domain.'" Updated="true" />');
                case 'namecheap.domains.getContacts':
                    if (! $known) {
                        return $missing;
                    }
                    $contact = fn (string $prefix, string $first) => "<{$prefix} ReadOnly=\"false\"><OrganizationName>Acme Inc</OrganizationName><FirstName>{$first}</FirstName><LastName>Smith</LastName>"
                        .'<Address1>1 Main St</Address1><Address2></Address2><City>Boston</City><StateProvince>MA</StateProvince><PostalCode>02101</PostalCode><Country>US</Country>'
                        ."<Phone>+1.6175551234</Phone><EmailAddress>john@acme.com</EmailAddress></{$prefix}>";

                    return $this->namecheapReply($command, '<DomainContactsResult Domain="'.$domain.'">'.$contact('Registrant', 'John').$contact('Tech', 'Tom').$contact('Admin', 'Jane').$contact('AuxBilling', 'Bill').'</DomainContactsResult>');
                case 'namecheap.domains.setContacts':
                    $this->namecheapDomains[$domain]['contacts'] = $params;

                    return $this->namecheapReply($command, '<DomainSetContactResult Domain="'.$domain.'" IsSuccess="true" />');
                case 'namecheap.domains.ns.create':
                case 'namecheap.domains.ns.update':
                    $this->namecheapHosts[$params['Nameserver']] = $params['IP'];

                    return $this->namecheapReply($command, '<DomainNSCreateResult Domain="'.$domain.'" Nameserver="'.$params['Nameserver'].'" IsSuccess="true" />');
                case 'namecheap.domains.ns.delete':
                    unset($this->namecheapHosts[$params['Nameserver']]);

                    return $this->namecheapReply($command, '<DomainNSDeleteResult Domain="'.$domain.'" IsSuccess="true" />');
                case 'namecheap.domains.getRegistrarLock':
                    return $this->namecheapReply($command, '<DomainGetRegistrarLockResult Domain="'.$domain.'" RegistrarLockStatus="'.(($this->namecheapDomains[$domain]['locked'] ?? true) ? 'true' : 'false').'" />');
                case 'namecheap.domains.setRegistrarLock':
                    $this->namecheapDomains[$domain]['locked'] = $params['LockAction'] === 'LOCK';

                    return $this->namecheapReply($command, '<DomainSetRegistrarLockResult Domain="'.$domain.'" IsSuccess="true" />');
                case 'namecheap.users.getPricing':
                    $kind = strtolower($params['ProductCategory']);
                    $products = '';
                    foreach ($this->namecheapPrices as $tld => $prices) {
                        if (isset($prices[$kind])) {
                            $products .= '<Product Name="'.$tld.'"><Price Duration="1" DurationType="YEAR" Price="'.$prices[$kind].'" YourPrice="'.$prices[$kind].'" Currency="USD" />'
                                .'<Price Duration="2" DurationType="YEAR" Price="99" YourPrice="99" Currency="USD" /></Product>';
                        }
                    }

                    return $this->namecheapReply($command, '<UserGetPricingResult><ProductType Name="domains"><ProductCategory Name="'.$kind.'">'.$products.'</ProductCategory></ProductType></UserGetPricingResult>');
            }

            return $this->namecheapReply($command, '', "Unknown command {$command}");
        });
    }

    private function namecheapReply(string $command, string $body, ?string $error = null): PromiseInterface
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="'.($error === null ? 'OK' : 'ERROR').'" xmlns="http://api.namecheap.com/xml.response">'
            .($error === null ? '<Errors />' : '<Errors><Error Number="2019166">'.htmlspecialchars($error, ENT_XML1).'</Error></Errors>')
            .'<RequestedCommand>'.$command.'</RequestedCommand>'
            .($error === null ? '<CommandResponse Type="'.$command.'">'.$body.'</CommandResponse>' : '')
            .'<Server>TEST</Server><ExecutionTime>0.1</ExecutionTime></ApiResponse>';

        return Http::response($xml, 200, ['Content-Type' => 'text/xml']);
    }

    /** @return list<string> the commands called, in order */
    protected function namecheapCommands(): array
    {
        return array_column($this->namecheapCalls, 0);
    }
}

<?php

namespace Tests;

use App\Models\Registrar;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\ResellerClub\ResellerClubClient;

/**
 * A tiny in-memory ResellerClub account: domains (as orders), customers and contacts, answering the calls GaiaCP uses.
 * $resellerclubCalls records [call, params] pairs without the login; a repeated parameter (ns) is a list.
 */
trait FakesResellerClub
{
    /** @var array<string, array<string, mixed>> by domain name */
    protected array $resellerclubDomains = [];

    /** @var array<string, string> email => customer ID */
    protected array $resellerclubCustomers = [];

    /** @var array<string, array<string, string>> contact ID => fields */
    protected array $resellerclubContacts = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    protected array $resellerclubCalls = [];

    protected Registrar $resellerclubRegistrar;

    protected function fakeResellerClub(): void
    {
        $this->resellerclubRegistrar = Registrar::factory()->withTlds(['.com', '.net'])->create([
            'name' => 'ResellerClub', 'module' => 'resellerclub', 'credentials' => ['reseller_id' => '123456', 'api_key' => 'secret'],
        ]);

        Http::fake(function (Request $request) {
            $url = strtok($request->url(), '?');
            $base = str_starts_with($url, ResellerClubClient::TEST_URL) ? ResellerClubClient::TEST_URL : ResellerClubClient::LIVE_URL;

            if (! str_starts_with($url, $base) || ! str_ends_with($url, '.json')) {
                return Http::response('Not found', 404);
            }

            $call = substr($url, strlen($base), -5);
            $raw = $request->method() === 'GET' ? (string) parse_url($request->url(), PHP_URL_QUERY) : $request->body();
            $params = [];
            foreach (explode('&', $raw) as $pair) {
                [$k, $v] = array_map('rawurldecode', explode('=', $pair, 2) + [1 => '']);
                isset($params[$k]) ? $params[$k] = [...(array) $params[$k], $v] : $params[$k] = $v;
            }

            if (($params['auth-userid'] ?? null) !== '123456' || ($params['api-key'] ?? null) !== 'secret') {
                return Http::response(['status' => 'ERROR', 'message' => 'Invalid auth-userid or api-key'], 500);
            }

            unset($params['auth-userid'], $params['api-key']);
            $this->resellerclubCalls[] = [$call, $params];
            $byOrder = fn (string $id) => array_key_first(array_filter($this->resellerclubDomains, fn (array $d) => (string) $d['orderid'] === $id));
            $contact = fn (string $id) => [
                'name' => $this->resellerclubContacts[$id]['name'], 'company' => $this->resellerclubContacts[$id]['company'], 'emailaddr' => $this->resellerclubContacts[$id]['email'] ?? '',
                'address1' => $this->resellerclubContacts[$id]['address-line-1'] ?? '', 'city' => $this->resellerclubContacts[$id]['city'] ?? '', 'state' => $this->resellerclubContacts[$id]['state'] ?? '',
                'zip' => $this->resellerclubContacts[$id]['zipcode'] ?? '', 'country' => $this->resellerclubContacts[$id]['country'] ?? '',
                'telnocc' => $this->resellerclubContacts[$id]['phone-cc'] ?? '', 'telno' => $this->resellerclubContacts[$id]['phone'] ?? '',
            ];

            switch ($call) {
                case 'domains/available':
                    $name = $params['domain-name'].'.'.$params['tlds'];

                    return Http::response([$name => ['classkey' => 'domcno', 'status' => isset($this->resellerclubDomains[$name]) ? 'regthroughus' : 'available']]);
                case 'customers/details':
                    return isset($this->resellerclubCustomers[$params['username']])
                        ? Http::response(['customerid' => $this->resellerclubCustomers[$params['username']], 'username' => $params['username']])
                        : Http::response(['status' => 'ERROR', 'message' => 'Customer not found'], 500);
                case 'customers/v2/signup':
                    $this->resellerclubCustomers[$params['username']] = (string) (500 + count($this->resellerclubCustomers));

                    return Http::response($this->resellerclubCustomers[$params['username']]);
                case 'contacts/add':
                    $id = (string) (900 + count($this->resellerclubContacts));
                    $this->resellerclubContacts[$id] = $params;

                    return Http::response($id);
                case 'domains/register':
                    $this->resellerclubDomains[$params['domain-name']] = [
                        'orderid' => 7000 + count($this->resellerclubDomains), 'customerid' => $params['customer-id'], 'endtime' => (string) now()->addYears((int) $params['years'])->timestamp,
                        'ns' => (array) ($params['ns'] ?? ['dns1.resellerclub.com']), 'domsecret' => 'Rc#Code1', 'orderstatus' => ['transferlock'], 'cns' => [],
                        'registrantcontactid' => $params['reg-contact-id'], 'admincontactid' => $params['admin-contact-id'], 'techcontactid' => $params['tech-contact-id'], 'billingcontactid' => $params['billing-contact-id'],
                    ];

                    return Http::response(['actiontypedesc' => 'Registration', 'actionstatus' => 'Success', 'status' => 'Success', 'entityid' => '1']);
                case 'domains/transfer':
                    return Http::response(['actiontypedesc' => 'Transfer', 'actionstatus' => 'Success', 'status' => 'Success']);
                case 'domains/details-by-name':
                    $d = $this->resellerclubDomains[$params['domain-name']] ?? null;
                    if ($d === null) {
                        return Http::response(['status' => 'ERROR', 'message' => "Website doesn't exist for {$params['domain-name']}"], 500);
                    }
                    $reply = ['orderid' => (string) $d['orderid'], 'domainname' => $params['domain-name'], 'customerid' => $d['customerid'], 'endtime' => $d['endtime'],
                        'domsecret' => $d['domsecret'], 'orderstatus' => $d['orderstatus'], 'cns' => $d['cns']];
                    foreach (array_values($d['ns']) as $i => $ns) {
                        $reply['ns'.($i + 1)] = $ns;
                    }
                    foreach (['registrant', 'admin', 'tech', 'billing'] as $role) {
                        $reply["{$role}contactid"] = $d["{$role}contactid"];
                        $reply["{$role}contact"] = $contact($d["{$role}contactid"]);
                    }

                    return Http::response($reply);
            }

            $name = $byOrder((string) ($params['order-id'] ?? ''));
            if ($name === null) {
                return Http::response(['status' => 'ERROR', 'message' => 'Invalid order-id'], 500);
            }

            switch ($call) {
                case 'domains/renew':
                    if ($params['exp-date'] !== $this->resellerclubDomains[$name]['endtime']) {
                        return Http::response(['status' => 'ERROR', 'message' => 'Expiry date mismatch'], 500);
                    }
                    $this->resellerclubDomains[$name]['endtime'] = (string) now()->createFromTimestampUTC((int) $params['exp-date'])->addYears((int) $params['years'])->timestamp;

                    return Http::response(['actiontypedesc' => 'Renewal', 'actionstatus' => 'Success', 'status' => 'Success']);
                case 'domains/modify-ns':
                    $this->resellerclubDomains[$name]['ns'] = (array) $params['ns'];
                    break;
                case 'domains/modify-contact':
                    foreach (['reg' => 'registrant', 'admin' => 'admin', 'tech' => 'tech', 'billing' => 'billing'] as $param => $role) {
                        $this->resellerclubDomains[$name]["{$role}contactid"] = $params["{$param}-contact-id"];
                    }
                    break;
                case 'domains/add-cns':
                    $this->resellerclubDomains[$name]['cns'][$params['cns']] = [$params['ip']];
                    break;
                case 'domains/modify-cns-ip':
                    $this->resellerclubDomains[$name]['cns'][$params['cns']] = [$params['new-ip']];
                    break;
                case 'domains/delete-cns-ip':
                    unset($this->resellerclubDomains[$name]['cns'][$params['cns']]);
                    break;
                case 'domains/enable-theft-protection':
                case 'domains/disable-theft-protection':
                    $this->resellerclubDomains[$name]['orderstatus'] = $call === 'domains/enable-theft-protection' ? ['transferlock'] : [];
                    break;
                default:
                    return Http::response(['status' => 'ERROR', 'message' => "Unknown call {$call}"], 404);
            }

            return Http::response(['actionstatus' => 'Success', 'status' => 'Success']);
        });
    }

    /** @return list<string> the calls, in order */
    protected function resellerclubCallNames(): array
    {
        return array_column($this->resellerclubCalls, 0);
    }
}

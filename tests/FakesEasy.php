<?php

namespace Tests;

use App\Models\Registrar;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\EasyGr\EasyRegistrar;

/**
 * A tiny in-memory easy.gr account: keeps domains (expiry, nameservers, EPP
 * code) and answers the action codes GaiaCP uses. $easyCalls records
 * [action, params] pairs without the credentials.
 */
trait FakesEasy
{
    /** @var array<string, array{expires: string, ns?: list<string>, epp?: string}> */
    protected array $easyDomains = [];

    /** @var list<array{0: int, 1: array<string, mixed>}> */
    protected array $easyCalls = [];

    /** @var array<int, array<string, string>> action => errors map to return with done=0 */
    protected array $easyFailures = [];

    /** The registrar account the fake answers for; sells .gr, .com and .com.gr. */
    protected Registrar $easyRegistrar;

    protected function fakeEasy(): void
    {
        $this->easyRegistrar = Registrar::factory()->withTlds()->create();

        Http::fake(function (Request $request) {
            // Anything else (e.g. a WHM package lookup on a hosting form) is not ours to answer.
            if (! str_starts_with($request->url(), 'https://api.easy.gr')) {
                return Http::response('Not found', 404);
            }

            parse_str($request->method() === 'GET' ? (string) parse_url($request->url(), PHP_URL_QUERY) : $request->body(), $params);
            $action = (int) $params['action'];
            unset($params['username'], $params['password'], $params['action']);
            $this->easyCalls[] = [$action, $params];

            if (isset($this->easyFailures[$action])) {
                return Http::response(['done' => 0, 'errors' => $this->easyFailures[$action]]);
            }

            $domain = $params['domain'] ?? '';
            $ok = fn (array $extra = []) => Http::response(['done' => 1, ...$extra]);
            $missing = Http::response(['done' => 0, 'errors' => ['2303' => 'Object does not exist']]);

            switch ($action) {
                case EasyRegistrar::AVAILABILITY: // plain text, as whoisservers.php expects
                    return Http::response(isset($this->easyDomains[$domain]) ? "Domain {$domain} exists" : "Domain {$domain} does not exist");
                case EasyRegistrar::SYNC:
                    return isset($this->easyDomains[$domain])
                        ? $ok(['date_expire' => $this->easyDomains[$domain]['expires'].' 00:00:00'])
                        : $missing;
                case EasyRegistrar::REGISTER:
                    $this->easyDomains[$domain] = [
                        'expires' => now()->addYears((int) $params['years'])->toDateString(),
                        'ns' => array_values(array_filter([$params['ns1'], $params['ns2'], $params['ns3'], $params['ns4']])),
                    ];

                    return $ok();
                case EasyRegistrar::TRANSFER:
                    return $ok();
                case EasyRegistrar::RENEW:
                    if (! isset($this->easyDomains[$domain])) {
                        return $missing;
                    }
                    $this->easyDomains[$domain]['expires'] = now()->parse($this->easyDomains[$domain]['expires'])->addYears((int) $params['years'])->toDateString();

                    return $ok();
                case EasyRegistrar::GET_NAMESERVERS:
                    if (! isset($this->easyDomains[$domain])) {
                        return $missing;
                    }
                    $ns = $this->easyDomains[$domain]['ns'] ?? [];

                    return $ok(['response' => collect($ns)->mapWithKeys(fn ($n, $i) => ['ns'.($i + 1) => $n])->all()]);
                case EasyRegistrar::SAVE_NAMESERVERS:
                    $this->easyDomains[$domain]['ns'] = array_values(array_filter([$params['ns1'], $params['ns2'], $params['ns3'], $params['ns4']]));

                    return $ok();
                case EasyRegistrar::GET_CONTACTS:
                    if (! isset($this->easyDomains[$domain])) {
                        return $missing;
                    }
                    $contact = fn (string $name) => ['fname' => $name, 'lname' => 'Papa', 'org' => '', 'street1' => 'Ermou 1', 'street2' => '', 'city' => 'Athens',
                        'pc' => '10563', 'sp' => 'Attica', 'voice' => '2101234567', 'phone_code' => '30', 'fax' => '', 'email' => 'maria@example.gr', 'cc' => 'GR'];

                    return $ok(['response' => $this->easyDomains[$domain]['contacts'] ?? [
                        'registrant' => $contact('Maria'), 'admin' => $contact('Admin'), 'tech' => $contact('Tech'),
                    ]]);
                case EasyRegistrar::SAVE_CONTACTS:
                    foreach (EasyRegistrar::CONTACT_ROLES as $i => $role) {
                        $this->easyDomains[$domain]['saved_contacts'][$role] = collect($params)
                            ->filter(fn ($value, string $key) => str_ends_with($key, (string) $i) && $key !== 'domain')
                            ->mapWithKeys(fn ($value, string $key) => [substr($key, 0, -1) => $value])->all();
                    }

                    return $ok();
                case EasyRegistrar::SAVE_CHILD_NAMESERVER:
                    $this->easyDomains[$domain]['hosts'][$params['ns']] = $params['ip'];

                    return $ok();
                case EasyRegistrar::DELETE_CHILD_NAMESERVER:
                    unset($this->easyDomains[$domain]['hosts'][$params['ns']]);

                    return $ok();
                case EasyRegistrar::EPP_CODE:
                    return isset($this->easyDomains[$domain]) ? $ok(['response' => $this->easyDomains[$domain]['epp'] ?? 'Epp-Code-1']) : $missing;
            }

            return Http::response(['done' => 0, 'errors' => ['action' => "Unknown action {$action}"]]);
        });
    }

    /** @return list<int> the actions called, in order */
    protected function easyActions(): array
    {
        return array_column($this->easyCalls, 0);
    }
}

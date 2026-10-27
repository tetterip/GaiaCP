<?php

namespace Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny in-memory WHM: keeps accounts (user => suspended?) and answers the
 * API 1 functions GaiaCP uses. $whmCalls records [function, params] pairs.
 */
trait FakesWhm
{
    /** @var array<string, bool> username => suspended */
    protected array $whmAccounts = [];

    /** @var array<string, string> username => main domain (defaults to "<user>.example") */
    protected array $whmDomains = [];

    /** @var array<string, array{diskused?: string, disklimit?: string, totalbytes?: int, limit?: int|string, stats?: list<array<string, mixed>>}> username => usage (stats = StatsBar items) */
    protected array $whmUsage = [];

    /** @var array<string, string> username => package (plan), listed by listaccts without a search; 'basic' when unset */
    protected array $whmPlans = [];

    /** @var array<string, string> path => contents of the files saved with UAPI Fileman::save_file_content */
    protected array $whmFiles = [];

    /** @var array<string, array{user: string, cert: string, key: string, cabundle: ?string}> domain => certificate installed with UAPI SSL::install_ssl */
    protected array $whmCertificates = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    protected array $whmCalls = [];

    /** @var array<string, string> function => error reason to return */
    protected array $whmFailures = [];

    protected function fakeWhm(): void
    {
        Http::fake(function (Request $request) {
            // Not a WHM call (e.g. a registrar's): leave it to another fake.
            if (! str_contains($request->url(), '/json-api/')) {
                return null;
            }

            preg_match('#/json-api/([a-z_]+)#', $request->url(), $m);
            $function = $m[1] ?? '';
            parse_str($request->body(), $params);
            $this->whmCalls[] = [$function, $params];

            if (isset($this->whmFailures[$function])) {
                return Http::response(['metadata' => ['result' => 0, 'reason' => $this->whmFailures[$function]]]);
            }

            $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);

            switch ($function) {
                case 'version':
                    return $ok(['version' => '11.130.0.4']);
                case 'listpkgs':
                    return $ok(['pkg' => [['name' => 'basic'], ['name' => 'pro']]]);
                case 'listaccts':
                    if (! isset($params['search'])) {
                        return $ok(['acct' => collect($this->whmAccounts)->map(fn (bool $suspended, string $user) => [
                            'user' => $user, 'domain' => $this->whmDomains[$user] ?? "{$user}.example",
                            'plan' => $this->whmPlans[$user] ?? 'basic', 'suspended' => (int) $suspended,
                        ])->values()->all()]);
                    }
                    $user = trim(stripslashes($params['search'] ?? ''), '^$');
                    $acct = array_key_exists($user, $this->whmAccounts)
                        ? [['user' => $user, 'domain' => $this->whmDomains[$user] ?? "{$user}.example", 'suspended' => (int) $this->whmAccounts[$user]]]
                        : [];

                    return $ok(['acct' => $acct]);
                case 'accountsummary':
                    $user = $params['user'] ?? '';
                    if (! array_key_exists($user, $this->whmAccounts)) {
                        return Http::response(['metadata' => ['result' => 0, 'reason' => "Account Does Not Exist: {$user}"]]);
                    }
                    $usage = $this->whmUsage[$user] ?? [];

                    return $ok(['acct' => [['user' => $user, 'diskused' => $usage['diskused'] ?? '0M', 'disklimit' => $usage['disklimit'] ?? 'unlimited']]]);
                case 'showbw':
                    $user = trim(stripslashes($params['search'] ?? ''), '^$');
                    $usage = $this->whmUsage[$user] ?? [];
                    $acct = array_key_exists($user, $this->whmAccounts)
                        ? [['user' => $user, 'totalbytes' => $usage['totalbytes'] ?? 0, 'limit' => $usage['limit'] ?? 'unlimited']]
                        : [];

                    return $ok(['acct' => $acct, 'month' => 10, 'year' => 2026]);
                case 'uapi_cpanel':
                    // parse_str() turns the dots of "cpanel.user" into underscores
                    $user = $params['cpanel_user'] ?? $params['cpanel.user'] ?? '';
                    $uapi = ($params['cpanel_module'] ?? '').'::'.($params['cpanel_function'] ?? '');

                    if (isset($this->whmFailures[$uapi])) {
                        return $ok(['uapi' => ['status' => 0, 'errors' => [$this->whmFailures[$uapi]], 'data' => null]]);
                    }

                    $data = match ($uapi) {
                        'DomainInfo::single_domain_data' => ['domain' => $params['domain'], 'documentroot' => "/home/{$user}/public_html", 'homedir' => "/home/{$user}"],
                        'Fileman::save_file_content' => tap(['path' => "{$params['dir']}/{$params['file']}"], fn () => $this->whmFiles["{$params['dir']}/{$params['file']}"] = $params['content']),
                        'SSL::install_ssl' => tap(['domain' => $params['domain']], fn () => $this->whmCertificates[$params['domain']] = ['user' => $user, 'cert' => $params['cert'], 'key' => $params['key'], 'cabundle' => $params['cabundle'] ?? null]),
                        default => $this->whmUsage[$user]['stats'] ?? [],
                    };

                    return $ok(['uapi' => ['status' => 1, 'errors' => null, 'data' => $data]]);
                case 'cpanel':
                    return $ok(['cpanelresult' => ['event' => ['result' => 1], 'data' => []]]);
                case 'createacct':
                    $this->whmAccounts[$params['username']] = false;
                    $this->whmDomains[$params['username']] = $params['domain'];

                    return $ok();
                case 'suspendacct':
                    $this->whmAccounts[$params['user']] = true;

                    return $ok();
                case 'unsuspendacct':
                    $this->whmAccounts[$params['user']] = false;

                    return $ok();
                case 'removeacct':
                    unset($this->whmAccounts[$params['username']]);

                    return $ok();
                case 'create_user_session':
                    if (($params['service'] ?? '') === 'whostmgrd') {
                        return $ok(['url' => "https://whm.test:2087/cpsess456/login/?session={$params['user']}:token", 'expires' => 1]);
                    }

                    if (! array_key_exists($params['user'] ?? '', $this->whmAccounts)) {
                        return Http::response(['metadata' => ['result' => 0, 'reason' => "The user {$params['user']} does not exist."]]);
                    }

                    return $ok(['url' => "https://whm.test:2083/cpsess123/login/?session={$params['user']}:token", 'expires' => 1]);
                case 'changepackage':
                case 'passwd':
                    return $ok();
            }

            return Http::response(['metadata' => ['result' => 0, 'reason' => "Unknown function {$function}"]]);
        });
    }

    /** @return list<string> the WHM functions called, in order */
    protected function whmFunctions(): array
    {
        return array_column($this->whmCalls, 0);
    }
}

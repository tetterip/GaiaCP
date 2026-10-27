<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny in-memory DirectAdmin legacy API (/CMD_API_*): the login's users with their config and usage, its packages
 * and IPs, answering URL-encoded like DirectAdmin ("error=1&text=…&details=…" on failure). $daCalls records
 * [command, method, params].
 */
trait FakesDirectAdmin
{
    /** @var array<string, array<string, string>> username => SHOW_USER_CONFIG fields (domain, package, suspended, quota, …) plus 'password' */
    protected array $daUsers = [];

    /** @var array<string, array<string, string>> username => SHOW_USER_USAGE fields */
    protected array $daUsage = [];

    /** @var list<string> */
    protected array $daPackages = ['basic', 'pro'];

    /** @var list<string> */
    protected array $daIps = ['203.0.113.10', '203.0.113.11'];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    protected array $daCalls = [];

    /** @var array<string, string> command => error text */
    protected array $daFailures = [];

    /** Answered to every request instead (e.g. a rejected login or a web page). */
    protected ?PromiseInterface $daRawResponse = null;

    protected function fakeDirectAdmin(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (! str_starts_with($path, '/CMD_API_')) {
                return null;
            }

            $command = substr($path, strlen('/CMD_API_'));
            parse_str($request->method() === 'POST' ? $request->body() : (string) parse_url($request->url(), PHP_URL_QUERY), $params);
            $this->daCalls[] = [$command, $request->method(), $params];

            if ($this->daRawResponse !== null) {
                return $this->daRawResponse;
            }

            if (isset($this->daFailures[$command])) {
                return $this->daError($this->daFailures[$command]);
            }

            return $this->daHandle($command, $params);
        });
    }

    /** @param  array<string, mixed>  $params */
    private function daHandle(string $command, array $params): PromiseInterface
    {
        $user = (string) ($params['user'] ?? $params['username'] ?? $params['select0'] ?? '');

        switch ($command) {
            case 'SHOW_USERS':
                return $this->daList(array_keys($this->daUsers));
            case 'PACKAGES_USER':
                return $this->daList($this->daPackages);
            case 'SHOW_RESELLER_IPS':
                return $this->daList($this->daIps);
            case 'SHOW_USER_CONFIG':
                if (! isset($this->daUsers[$user])) {
                    return $this->daError('Unable to show user', "{$user} is not a valid user");
                }
                $config = $this->daUsers[$user];
                unset($config['password']);

                return $this->daOk($config);
            case 'SHOW_USER_USAGE':
                return $this->daOk($this->daUsage[$user] ?? ['quota' => '0', 'bandwidth' => '0']);
            case 'ACCOUNT_USER':
                if (isset($this->daUsers[$user])) {
                    return $this->daError('Unable to Create User', 'That username already exists on the system');
                }
                if (! in_array($params['package'] ?? '', $this->daPackages, true)) {
                    return $this->daError('Unable to Create User', 'Package does not exist');
                }
                $this->daUsers[$user] = [
                    'username' => $user, 'usertype' => 'user', 'domain' => (string) $params['domain'], 'package' => (string) $params['package'],
                    'email' => (string) $params['email'], 'ip' => (string) $params['ip'], 'suspended' => 'no', 'password' => (string) $params['passwd'],
                    'quota' => '1000', 'bandwidth' => 'unlimited', 'nemails' => '10', 'mysql' => '5', 'vdomains' => '1', 'domainptr' => '0', 'nsubdomains' => 'unlimited',
                ];

                return $this->daOk(['text' => 'User created successfully']);
            case 'SELECT_USERS':
                if (! isset($this->daUsers[$user])) {
                    return $this->daError('Error with selection', "{$user} does not exist");
                }
                if (($params['delete'] ?? null) === 'yes' && ($params['confirmed'] ?? null) === 'Confirm') {
                    unset($this->daUsers[$user]);
                } elseif (in_array($params['suspend'] ?? null, ['Suspend', 'Unsuspend'], true)) {
                    $this->daUsers[$user]['suspended'] = $params['suspend'] === 'Suspend' ? 'yes' : 'no';
                } else {
                    return $this->daError('Error with selection', 'Nothing to do');
                }

                return $this->daOk(['text' => 'Success']);
            case 'MODIFY_USER':
                if (($params['action'] ?? null) !== 'package' || ! in_array($params['package'] ?? '', $this->daPackages, true)) {
                    return $this->daError('Unable to modify user', 'Package does not exist');
                }
                $this->daUsers[$user]['package'] = (string) $params['package'];

                return $this->daOk(['text' => 'User modified']);
            case 'USER_PASSWD':
                $this->daUsers[$user]['password'] = (string) $params['passwd'];

                return $this->daOk(['text' => 'Password changed']);
        }

        return $this->daError('Unknown command', $command);
    }

    /** @param  list<string>  $items */
    private function daList(array $items): PromiseInterface
    {
        return Http::response(implode('&', array_map(fn (string $item) => 'list[]='.urlencode($item), $items)), 200, ['Content-Type' => 'text/plain']);
    }

    /** @param  array<string, string>  $fields */
    private function daOk(array $fields): PromiseInterface
    {
        return Http::response(http_build_query(['error' => '0'] + $fields), 200, ['Content-Type' => 'text/plain']);
    }

    private function daError(string $text, string $details = ''): PromiseInterface
    {
        return Http::response(http_build_query(['error' => '1', 'text' => $text, 'details' => $details]), 200, ['Content-Type' => 'text/plain']);
    }

    /** @return list<string> the commands called, in order */
    protected function daCommands(): array
    {
        return array_column($this->daCalls, 0);
    }
}

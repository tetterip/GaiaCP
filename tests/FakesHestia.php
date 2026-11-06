<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny in-memory HestiaCP API (/api/): users with their package, domains and usage, and packages. Answers like an
 * access key (Hestia-Exit-Code header, HTTP error status on failure), or like the password login when
 * $hestiaPasswordLogin is set (HTTP 200 and "Error: …" text). $hestiaCalls records [command, args, all params].
 */
trait FakesHestia
{
    /** @var array<string, array<string, mixed>> username => v-list-user fields (SUSPENDED, PACKAGE, U_DISK, …) plus 'domains' => list<string> */
    protected array $hestiaUsers = [];

    /** @var list<string> */
    protected array $hestiaPackages = ['default', 'system', 'Starter', 'Business'];

    /** @var list<array{0: string, 1: list<string>, 2: array<string, string>}> */
    protected array $hestiaCalls = [];

    /** @var array<string, array{0: int, 1: string}> command => [exit code, message] */
    protected array $hestiaFailures = [];

    protected bool $hestiaPasswordLogin = false;

    protected function fakeHestia(): void
    {
        Http::fake(function (Request $request) {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/api/')) {
                return null;
            }

            parse_str($request->body(), $params);
            $command = $params['cmd'] ?? '';
            $args = [];
            for ($i = 1; isset($params["arg{$i}"]); $i++) {
                $args[] = $params["arg{$i}"];
            }
            $this->hestiaCalls[] = [$command, $args, $params];

            if (isset($this->hestiaFailures[$command])) {
                return $this->hestiaError(...$this->hestiaFailures[$command]);
            }

            return $this->hestiaHandle($command, $args);
        });
    }

    /** @param  list<string>  $args */
    private function hestiaHandle(string $command, array $args): PromiseInterface
    {
        $user = $args[0] ?? '';
        $exists = isset($this->hestiaUsers[$user]);
        $missing = fn () => $this->hestiaError(3, "user {$user} doesn't exist");

        switch ($command) {
            case 'v-list-sys-info':
                return $this->hestiaOk(json_encode(['sysinfo' => ['HOSTNAME' => 'hst.test', 'OS' => 'Ubuntu', 'VERSION' => '24.04', 'ARCH' => 'x86_64',
                    'HESTIA' => '1.10.5', 'RELEASE' => 'release', 'UPTIME' => '0', 'LOADAVERAGE' => '0.21 / 0.06 / 0.02']], JSON_PRETTY_PRINT));

            case 'v-list-user-packages':
                // Every package with its limits, as HestiaCP 1.10 lists them (the built-in "system" one included when it is there)
                return $this->hestiaOk(json_encode(array_fill_keys($this->hestiaPackages, ['WEB_TEMPLATE' => 'default', 'PROXY_TEMPLATE' => 'default',
                    'DNS_TEMPLATE' => 'default', 'WEB_DOMAINS' => '1', 'WEB_ALIASES' => '1', 'DNS_DOMAINS' => '1', 'DNS_RECORDS' => 'unlimited',
                    'MAIL_DOMAINS' => '1', 'MAIL_ACCOUNTS' => '1', 'DATABASES' => '0', 'CRON_JOBS' => 'unlimited', 'DISK_QUOTA' => 'unlimited',
                    'BANDWIDTH' => 'unlimited', 'NS' => 'ns1.example.com,ns2.example.com', 'SHELL' => 'nologin', 'BACKUPS' => '1',
                    'TIME' => '18:00:00', 'DATE' => '2019-01-15']), JSON_PRETTY_PRINT));

            case 'v-list-users':
                return $this->hestiaOk(json_encode(collect($this->hestiaUsers)->map(function (array $fields) {
                    unset($fields['domains'], $fields['password']);

                    return $fields;
                })->all() + ['admin' => ['NAME' => 'System Administrator', 'PACKAGE' => 'default', 'CONTACT' => 'admin@example.com', 'RKEY' => '2K085ASf0BT672D2',
                    'ROLE' => 'admin', 'SUSPENDED' => 'no', 'U_DISK' => '12', 'U_BANDWIDTH' => '0', 'LANGUAGE' => 'en']], JSON_PRETTY_PRINT));

            case 'v-list-user':
                if (! $exists) {
                    return $missing();
                }
                $fields = $this->hestiaUsers[$user];
                unset($fields['domains']);

                return $this->hestiaOk(json_encode([$user => $fields]));

            case 'v-list-web-domains':
                return $exists
                    ? $this->hestiaOk(json_encode(array_fill_keys($this->hestiaUsers[$user]['domains'], ['SUSPENDED' => 'no'])))
                    : $missing();

            case 'v-add-user':
                if ($exists) {
                    return $this->hestiaError(4, "user {$user} exists");
                }
                if (! in_array($args[3], $this->hestiaPackages, true)) {
                    return $this->hestiaError(3, "package {$args[3]} doesn't exist");
                }
                $this->hestiaUsers[$user] = ['NAME' => $args[4] ?? '', 'CONTACT' => $args[2], 'PACKAGE' => $args[3], 'ROLE' => 'user', 'SUSPENDED' => 'no', 'password' => $args[1], 'domains' => []];

                return $this->hestiaOk();

            case 'v-add-domain':
                if (! $exists) {
                    return $missing();
                }
                $this->hestiaUsers[$user]['domains'][] = $args[1];

                return $this->hestiaOk();

            case 'v-suspend-user':
            case 'v-unsuspend-user':
                if (! $exists) {
                    return $missing();
                }
                $this->hestiaUsers[$user]['SUSPENDED'] = $command === 'v-suspend-user' ? 'yes' : 'no';

                return $this->hestiaOk();

            case 'v-delete-user':
                if (! $exists) {
                    return $missing();
                }
                unset($this->hestiaUsers[$user]);

                return $this->hestiaOk();

            case 'v-change-user-package':
                if (! in_array($args[1], $this->hestiaPackages, true)) {
                    return $this->hestiaError(3, "package {$args[1]} doesn't exist");
                }
                $this->hestiaUsers[$user]['PACKAGE'] = $args[1];

                return $this->hestiaOk();

            case 'v-change-user-password':
                $this->hestiaUsers[$user]['password'] = $args[1];

                return $this->hestiaOk();
        }

        return $this->hestiaError(10, "Command {$command} not found");
    }

    private function hestiaOk(string $body = ''): PromiseInterface
    {
        if ($this->hestiaPasswordLogin) {
            return Http::response($body === '' ? 'OK' : $body);
        }

        return Http::response($body, $body === '' ? 204 : 200, ['Hestia-Exit-Code' => '0']);
    }

    private function hestiaError(int $code, string $message): PromiseInterface
    {
        if ($this->hestiaPasswordLogin) {
            return Http::response("Error: {$message}");
        }

        return Http::response("Error: {$message}", in_array($code, [5, 6, 9, 10], true) ? 401 : 400, ['Hestia-Exit-Code' => (string) $code]);
    }

    /** @return list<string> the commands run, in order */
    protected function hestiaCommands(): array
    {
        return array_column($this->hestiaCalls, 0);
    }
}

<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny in-memory Virtualmin remote API (/virtual-server/remote.cgi, json=1): top-level virtual servers with their
 * user, plan, password and list-domains --multiline fields, and plans. Answers {"status": "success"|"failure", …} like
 * remote.cgi; a wrong basic auth login gets HTTP 401. $virtualminCalls records [program, all params].
 */
trait FakesVirtualmin
{
    /** @var array<string, array<string, mixed>> domain => ['user' => …, 'plan' => …, 'pass' => …, 'disabled' => ?string, 'fields' => extra list-domains values] */
    protected array $virtualminDomains = [];

    /** @var list<string> */
    protected array $virtualminPlans = ['Default Plan', 'Starter', 'Business'];

    /** @var list<array{0: string, 1: array<string, string>}> */
    protected array $virtualminCalls = [];

    /** @var array<string, string> program => error */
    protected array $virtualminFailures = [];

    protected string $virtualminPassword = 'RootPass1';

    protected function fakeVirtualmin(): void
    {
        Http::fake(function (Request $request) {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/virtual-server/remote.cgi')) {
                return null;
            }

            if ($request->header('Authorization')[0] !== 'Basic '.base64_encode('root:'.$this->virtualminPassword)) {
                return Http::response('<html>Login failed</html>', 401);
            }

            parse_str($request->body(), $params);
            $program = $params['program'] ?? '';
            $this->virtualminCalls[] = [$program, $params];

            if (isset($this->virtualminFailures[$program])) {
                return $this->virtualminFail($this->virtualminFailures[$program]);
            }

            return $this->virtualminHandle($program, $params);
        });
    }

    /** @param  array<string, string>  $params */
    private function virtualminHandle(string $program, array $params): PromiseInterface
    {
        $domain = $params['domain'] ?? '';
        $exists = isset($this->virtualminDomains[$domain]);
        $missing = fn () => $this->virtualminFail("Virtual server {$domain} does not exist");

        switch ($program) {
            case 'list-plans':
                return $this->virtualminOk(['data' => array_map(fn (string $plan, int $i) => ['name' => (string) $i, 'values' => ['name' => [$plan], 'id' => [(string) $i]]], $this->virtualminPlans, array_keys($this->virtualminPlans))]);

            case 'list-domains':
                $rows = collect($this->virtualminDomains)
                    ->filter(fn (array $d) => ! isset($params['user']) || $d['user'] === $params['user'])
                    ->map(fn (array $d, string $name) => ['name' => $name, 'values' => array_filter([
                        'username' => [$d['user']],
                        'plan' => [$d['plan']],
                        'disabled' => isset($d['disabled']) ? [$d['disabled']] : null,
                    ]) + ($d['fields'] ?? [])])->values()->all();

                return $this->virtualminOk(['data' => $rows]);

            case 'create-domain':
                if ($exists) {
                    return $this->virtualminFail("A virtual server named {$domain} already exists");
                }
                if (! in_array($params['plan'] ?? '', $this->virtualminPlans, true)) {
                    return $this->virtualminFail("Plan {$params['plan']} does not exist");
                }
                $this->virtualminDomains[$domain] = ['user' => $params['user'], 'plan' => $params['plan'], 'pass' => $params['pass'], 'email' => $params['email'] ?? null, 'desc' => $params['desc'] ?? null];

                return $this->virtualminOk(['output' => "Creating virtual server {$domain} .. done"]);

            case 'disable-domain':
                if (! $exists) {
                    return $missing();
                }
                $this->virtualminDomains[$domain]['disabled'] = $params['why'] ?? 'Disabled';

                return $this->virtualminOk(['output' => 'done']);

            case 'enable-domain':
                if (! $exists) {
                    return $missing();
                }
                unset($this->virtualminDomains[$domain]['disabled']);

                return $this->virtualminOk(['output' => 'done']);

            case 'delete-domain':
                if (! $exists) {
                    return $missing();
                }
                unset($this->virtualminDomains[$domain]);

                return $this->virtualminOk(['output' => 'done']);

            case 'modify-domain':
                if (! $exists) {
                    return $missing();
                }
                if (isset($params['apply-plan'])) {
                    if (! in_array($params['apply-plan'], $this->virtualminPlans, true)) {
                        return $this->virtualminFail("Plan {$params['apply-plan']} does not exist");
                    }
                    $this->virtualminDomains[$domain]['plan'] = $params['apply-plan'];
                }
                if (isset($params['pass'])) {
                    $this->virtualminDomains[$domain]['pass'] = $params['pass'];
                }

                return $this->virtualminOk(['output' => 'done']);
        }

        return $this->virtualminFail("Unknown program {$program}");
    }

    /** @return list<string> */
    protected function virtualminPrograms(): array
    {
        return array_column($this->virtualminCalls, 0);
    }

    /** @param  array<string, mixed>  $data */
    private function virtualminOk(array $data): PromiseInterface
    {
        return Http::response(['command' => 'x', 'status' => 'success'] + $data);
    }

    private function virtualminFail(string $error): PromiseInterface
    {
        return Http::response(['command' => 'x', 'status' => 'failure', 'error' => $error]);
    }
}

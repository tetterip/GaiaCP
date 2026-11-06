<?php

namespace Tests;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny in-memory Proxmox VE API (/api2/json): KVM machines and templates with their config, users and ACLs. Tasks
 * (clone, start, stop, delete, resize) end at once with OK. $proxmoxCalls records "METHOD /path"; $proxmoxFailures
 * makes "METHOD /path" (a regex on it) answer an HTTP error with that reason. Answers are shaped like Proxmox VE 9.2's
 * (module log of a real server, 2026-11): an error comes as {"data":null,"message":"…\n"} under a plain status line,
 * as it does through a proxy, and a missing user is "no such user".
 */
trait FakesProxmox
{
    /** @var array<int, array<string, mixed>> vmid => node, name, template, status, tags, maxdisk, config */
    protected array $proxmoxVms = [];

    /** @var array<string, array<string, mixed>> userid => password, email, enable */
    protected array $proxmoxUsers = [];

    /** @var list<array<string, mixed>> */
    protected array $proxmoxAcl = [];

    /** @var list<string> */
    protected array $proxmoxCalls = [];

    /** @var array<string, array<string, mixed>> "METHOD /path" => params */
    protected array $proxmoxParams = [];

    /** @var array<string, array{0: int, 1: string}> regex => [status, reason] */
    protected array $proxmoxFailures = [];

    protected function fakeProxmox(): void
    {
        $this->proxmoxVms = [
            9000 => ['node' => 'pve1', 'name' => 'debian12-small', 'template' => 1, 'status' => 'stopped', 'maxdisk' => 10 * 1024 ** 3,
                'config' => ['cores' => 1, 'sockets' => 1, 'memory' => '2048', 'boot' => 'order=scsi0', 'scsi0' => 'local-lvm:base-9000-disk-0,size=10G', 'ide2' => 'local-lvm:vm-9000-cloudinit,media=cdrom']],
            9001 => ['node' => 'pve2', 'name' => 'debian12-large', 'template' => 1, 'status' => 'stopped', 'maxdisk' => 40 * 1024 ** 3,
                'config' => ['cores' => 4, 'sockets' => 1, 'memory' => '8192', 'boot' => 'order=scsi0', 'scsi0' => 'local-lvm:base-9001-disk-0,size=40G']],
        ];

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            if (! str_starts_with($path, '/api2/json/')) {
                return null;
            }

            $path = substr($path, strlen('/api2/json'));
            $method = $request->method();
            $call = "{$method} ".rawurldecode($path);
            $this->proxmoxCalls[] = $call;

            if (in_array($method, ['GET', 'DELETE'], true)) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
            } else {
                parse_str($request->body(), $params);
            }
            $this->proxmoxParams[$call] = $params;

            foreach ($this->proxmoxFailures as $pattern => [$status, $reason]) {
                if (preg_match($pattern, $call)) {
                    return $this->proxmoxError($status, $reason);
                }
            }

            try {
                return Http::response(['data' => $this->proxmoxAnswer($method, rawurldecode($path), $params)]);
            } catch (\RuntimeException $e) {
                return $this->proxmoxError(500, $e->getMessage());
            }
        });
    }

    /** An error as a real Proxmox answers it behind a proxy: the reason only in the body, the status line plain. */
    private function proxmoxError(int $status, string $reason): mixed
    {
        return Create::promiseFor(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode(['data' => null, 'message' => "{$reason}\n"])));
    }

    private function proxmoxAnswer(string $method, string $path, array $params): mixed
    {
        $task = fn (string $node) => "UPID:{$node}:00001234:00ABCDEF:6720F000:task:100:root@pam!gaiacp:";

        if ($path === '/version') {
            return ['repoid' => '41a0e2e09b9e6f9d', 'release' => '9.2', 'version' => '9.2.26'];
        }

        if ($path === '/cluster/resources') {
            return collect($this->proxmoxVms)->map(fn (array $vm, int $vmid) => [
                'id' => "qemu/{$vmid}", 'type' => 'qemu', 'vmid' => $vmid, 'node' => $vm['node'], 'name' => $vm['name'],
                'template' => $vm['template'], 'status' => $vm['status'], 'maxdisk' => $vm['maxdisk'], 'disk' => 0, 'mem' => 0, 'cpu' => 0, 'uptime' => 0,
                'maxcpu' => (int) ($vm['config']['cores'] ?? 1) * (int) ($vm['config']['sockets'] ?? 1), 'maxmem' => (int) ($vm['config']['memory'] ?? 0) * 1024 ** 2,
            ] + (filled($vm['tags'] ?? null) ? ['tags' => $vm['tags']] : []))->values()->push(['id' => 'lxc/200', 'type' => 'lxc', 'vmid' => 200, 'node' => 'pve1', 'name' => 'ct', 'template' => 0, 'status' => 'running'])->all();
        }

        if ($path === '/cluster/nextid') {
            return (string) max(100, ...array_map(fn ($id) => $id < 9000 ? $id + 1 : 100, array_keys($this->proxmoxVms)));
        }

        if (preg_match('#^/nodes/([^/]+)/tasks/(.+)/status$#', $path, $m)) {
            $parts = explode(':', $m[2]);

            return ['user' => 'root@pam', 'tokenid' => 'gaiacp', 'starttime' => 1793996834, 'exitstatus' => 'OK', 'upid' => $m[2], 'node' => $m[1],
                'status' => 'stopped', 'type' => $parts[5] ?? 'task', 'id' => $parts[6] ?? '', 'pid' => 63854, 'pstart' => 1257556];
        }

        if ($path === '/access/users' && $method === 'POST') {
            $this->proxmoxUsers[$params['userid']] = ['password' => $params['password'] ?? null, 'email' => $params['email'] ?? null, 'enable' => 1];

            return null;
        }

        if (preg_match('#^/access/users/(.+)$#', $path, $m)) {
            if (! isset($this->proxmoxUsers[$m[1]])) {
                throw new \RuntimeException("no such user ('{$m[1]}')");
            }

            if ($method === 'DELETE') {
                unset($this->proxmoxUsers[$m[1]]);
                $this->proxmoxAcl = array_values(array_filter($this->proxmoxAcl, fn ($acl) => $acl['users'] !== $m[1]));
            } else {
                $this->proxmoxUsers[$m[1]]['enable'] = (int) $params['enable'];
            }

            return null;
        }

        if ($path === '/access/acl') {
            $this->proxmoxAcl[] = $params;

            return null;
        }

        if (! preg_match('#^/nodes/([^/]+)/qemu/(\d+)(/.*)?$#', $path, $m)) {
            return null;
        }

        [$node, $vmid, $rest] = [$m[1], (int) $m[2], $m[3] ?? ''];

        if ($rest === '/clone') {
            $template = $this->proxmoxVms[$vmid];
            $this->proxmoxVms[(int) $params['newid']] = ['node' => $node, 'name' => $params['name'], 'template' => 0, 'status' => 'stopped', 'tags' => $template['tags'] ?? '',
                'maxdisk' => $template['maxdisk'], 'config' => $template['config'] + ['description' => $params['description'] ?? '']];

            return $task($node);
        }

        if ($rest === '' && $method === 'DELETE') {
            unset($this->proxmoxVms[$vmid]);

            return $task($node);
        }

        if ($rest === '/config') {
            if ($method === 'GET') {
                return $this->proxmoxVms[$vmid]['config'];
            }

            $this->proxmoxVms[$vmid]['config'] = array_merge($this->proxmoxVms[$vmid]['config'], $params);
            if (isset($params['tags'])) {
                $this->proxmoxVms[$vmid]['tags'] = $params['tags'];
            }

            return null;
        }

        if ($rest === '/resize') {
            $this->proxmoxVms[$vmid]['config'][$params['disk']] = preg_replace('/size=[^,]+/', 'size='.$params['size'], $this->proxmoxVms[$vmid]['config'][$params['disk']]);

            return $task($node);
        }

        if ($rest === '/agent/network-get-interfaces') {
            // Set $proxmoxVms[id]['agent'] to the addresses its guest agent reports; without it, no agent answers
            if (! isset($this->proxmoxVms[$vmid]['agent'])) {
                throw new \RuntimeException('QEMU guest agent is not running');
            }

            return ['result' => [
                ['name' => 'lo', 'ip-addresses' => [['ip-address' => '127.0.0.1', 'ip-address-type' => 'ipv4']]],
                ['name' => 'eth0', 'ip-addresses' => array_map(fn (string $ip) => ['ip-address' => $ip, 'ip-address-type' => str_contains($ip, ':') ? 'ipv6' : 'ipv4'], $this->proxmoxVms[$vmid]['agent'])],
            ]];
        }

        if ($rest === '/status/current') {
            return ['status' => $this->proxmoxVms[$vmid]['status'], 'vmid' => $vmid];
        }

        if (preg_match('#^/status/(start|stop|shutdown|reboot)$#', $rest, $s)) {
            $this->proxmoxVms[$vmid]['status'] = in_array($s[1], ['start', 'reboot'], true) ? 'running' : 'stopped';

            return $task($node);
        }

        return null;
    }
}

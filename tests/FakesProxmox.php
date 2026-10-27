<?php

namespace Tests;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny in-memory Proxmox VE API (/api2/json): KVM machines and templates with their config, users and ACLs. Tasks
 * (clone, start, stop, delete, resize) end at once with OK. $proxmoxCalls records "METHOD /path"; $proxmoxFailures
 * makes "METHOD /path" (a regex on it) answer an HTTP error with that reason.
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
            9000 => ['node' => 'pve1', 'name' => 'debian12-small', 'template' => 1, 'status' => 'stopped', 'tags' => '', 'maxdisk' => 10 * 1024 ** 3,
                'config' => ['cores' => 1, 'sockets' => 1, 'memory' => '2048', 'boot' => 'order=scsi0', 'scsi0' => 'local-lvm:base-9000-disk-0,size=10G', 'ide2' => 'local-lvm:vm-9000-cloudinit,media=cdrom']],
            9001 => ['node' => 'pve2', 'name' => 'debian12-large', 'template' => 1, 'status' => 'stopped', 'tags' => '', 'maxdisk' => 40 * 1024 ** 3,
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
                    return Create::promiseFor(new Psr7Response($status, ['Content-Type' => 'application/json'], '{"data":null}', '1.1', $reason));
                }
            }

            return Http::response(['data' => $this->proxmoxAnswer($method, rawurldecode($path), $params)]);
        });
    }

    private function proxmoxAnswer(string $method, string $path, array $params): mixed
    {
        $task = fn (string $node) => "UPID:{$node}:00001234:00ABCDEF:6720F000:task:100:root@pam!gaiacp:";

        if ($path === '/version') {
            return ['version' => '8.2.4', 'release' => '8.2'];
        }

        if ($path === '/cluster/resources') {
            return collect($this->proxmoxVms)->map(fn (array $vm, int $vmid) => [
                'id' => "qemu/{$vmid}", 'type' => 'qemu', 'vmid' => $vmid, 'node' => $vm['node'], 'name' => $vm['name'],
                'template' => $vm['template'], 'status' => $vm['status'], 'tags' => $vm['tags'], 'maxdisk' => $vm['maxdisk'],
            ])->values()->push(['id' => 'lxc/200', 'type' => 'lxc', 'vmid' => 200, 'node' => 'pve1', 'name' => 'ct', 'template' => 0, 'status' => 'running'])->all();
        }

        if ($path === '/cluster/nextid') {
            return (string) max(100, ...array_map(fn ($id) => $id < 9000 ? $id + 1 : 100, array_keys($this->proxmoxVms)));
        }

        if (preg_match('#^/nodes/([^/]+)/tasks/(.+)/status$#', $path, $m)) {
            return ['status' => 'stopped', 'exitstatus' => 'OK', 'type' => 'task', 'upid' => $m[2]];
        }

        if ($path === '/access/users' && $method === 'POST') {
            $this->proxmoxUsers[$params['userid']] = ['password' => $params['password'] ?? null, 'email' => $params['email'] ?? null, 'enable' => 1];

            return null;
        }

        if (preg_match('#^/access/users/(.+)$#', $path, $m)) {
            if ($method === 'DELETE') {
                unset($this->proxmoxUsers[$m[1]]);
                $this->proxmoxAcl = array_values(array_filter($this->proxmoxAcl, fn ($acl) => $acl['users'] !== $m[1]));
            } elseif (isset($this->proxmoxUsers[$m[1]])) {
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
            $this->proxmoxVms[(int) $params['newid']] = ['node' => $node, 'name' => $params['name'], 'template' => 0, 'status' => 'stopped', 'tags' => $template['tags'],
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

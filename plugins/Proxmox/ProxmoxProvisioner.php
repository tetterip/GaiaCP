<?php

namespace Plugins\Proxmox;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountState;
use App\Provisioning\AccountUsage;
use App\Provisioning\ControlsPower;
use App\Provisioning\HostingProvisioner;
use App\Provisioning\ListsAccounts;
use App\Provisioning\PanelAccount;
use App\Provisioning\ProvisioningException;
use App\Support\DomainName;

/**
 * Proxmox VE: one KVM virtual machine per hosting service (a VPS), a full clone of the template named by the service's
 * package, on the template's node. The clone keeps the template's CPU, memory and disk; cloud-init sets its user
 * (Settings → Proxmox, root by default) with the service's password, and its address from the server's pool (DHCP
 * when the server has none). The machine is found again by its tags: `gaiacp` and `gaiacp-user-<username>`, plus
 * `gaiacp-suspended` while suspended.
 *
 * The client gets a Proxmox user `<username>@pve` with the same password and a role (PVEVMUser by default) on that
 * machine only, to open its console in the Proxmox web interface. API tokens cannot change a password, so a new
 * password replaces that user.
 */
class ProxmoxProvisioner implements ControlsPower, HostingProvisioner, ListsAccounts
{
    private const TAG = 'gaiacp';

    private const USER_TAG = 'gaiacp-user-';

    private const SUSPENDED_TAG = 'gaiacp-suspended';

    private const REALM = 'pve';

    public function __construct(
        private ProxmoxClient $proxmox,
        private Server $server,
    ) {}

    public function testConnection(): string
    {
        $version = (array) $this->proxmox->get('/version');

        return 'Proxmox VE '.(($version['version'] ?? '') ?: 'unknown version');
    }

    /** The names of the cluster's KVM templates, each one a package. */
    public function packages(): array
    {
        return collect($this->vms())
            ->filter(fn (array $vm) => ! empty($vm['template']) && filled($vm['name'] ?? null))
            ->map(fn (array $vm) => (string) $vm['name'])
            ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public function accountState(Service $service): AccountState
    {
        $vm = $this->findVm($service);

        return match (true) {
            $vm === null => AccountState::Missing,
            in_array(self::SUSPENDED_TAG, $this->tags($vm), true) => AccountState::Suspended,
            default => AccountState::Active,
        };
    }

    /** The machine's name, which is the service's domain when it was made here. */
    public function accountDomain(Service $service): ?string
    {
        $vm = $this->findVm($service);

        if ($vm === null) {
            return null;
        }

        $name = (string) ($vm['name'] ?? '');

        return $service->domain && strcasecmp($name, $this->vmName($service)) === 0 ? $service->domain : ($name ?: null);
    }

    /** Every machine tagged by GaiaCP; the package (template) is not kept on the machine, so it is left out. */
    public function accounts(): array
    {
        return collect($this->vms())
            ->filter(fn (array $vm) => empty($vm['template']) && $this->usernameOf($vm) !== null)
            ->map(fn (array $vm) => new PanelAccount(
                username: $this->usernameOf($vm),
                domain: filled($vm['name'] ?? null) ? (string) $vm['name'] : null,
                package: null,
                suspended: in_array(self::SUSPENDED_TAG, $this->tags($vm), true),
            ))
            ->values()->all();
    }

    public function createAccount(Service $service, string $password): void
    {
        if (! $service->package || ! $service->username) {
            throw new ProvisioningException('A Proxmox VPS needs a package (the template to clone) and a username.');
        }

        $template = $this->template($service->package);
        $node = (string) $template['node'];
        $ipConfig = $this->ipConfig($service);
        $vmid = (int) $this->proxmox->get('/cluster/nextid');

        $this->proxmox->waitForTask($node, $this->proxmox->post($this->vmPath($node, (int) $template['vmid']).'/clone', array_filter([
            'newid' => $vmid,
            'name' => $this->vmName($service),
            'full' => true,
            'storage' => config('gaiacp.proxmox.storage') ?: null,
            'description' => "GaiaCP service #{$service->id} ({$service->client->name})",
        ], fn ($value) => $value !== null)));

        $vm = ['vmid' => $vmid, 'node' => $node];

        try {
            $this->proxmox->put($this->vmPath($node, $vmid).'/config', array_filter([
                'ciuser' => config('gaiacp.proxmox.ci_user') ?: 'root',
                'cipassword' => $password,
                'ipconfig0' => $ipConfig,
                'nameserver' => config('gaiacp.proxmox.nameserver') ?: null,
                'tags' => $this->tagList([self::TAG, self::USER_TAG.strtolower($service->username)]),
                'onboot' => true,
            ], fn ($value) => $value !== null), secrets: [$password]);

            $this->replaceUser($service, $vmid, $password);
            $this->proxmox->waitForTask($node, $this->proxmox->post($this->vmPath($node, $vmid).'/status/start'));
        } catch (ProvisioningException $e) {
            // Leave nothing half made, so a retry starts clean.
            rescue(fn () => $this->destroy($service, $vm), report: false);

            throw $e;
        }
    }

    /** Stops the machine, locks its Proxmox user and tags it suspended. Proxmox keeps no reason; it stays in GaiaCP. */
    public function suspendAccount(Service $service, string $reason): void
    {
        $vm = $this->vm($service);

        $this->stopIfRunning($vm);
        rescue(fn () => $this->proxmox->put('/access/users/'.rawurlencode($this->userId($service)), ['enable' => false]), report: false);
        $this->setTags($vm, [...$this->tags($vm), self::SUSPENDED_TAG]);
    }

    public function unsuspendAccount(Service $service): void
    {
        $vm = $this->vm($service);

        $this->setTags($vm, array_diff($this->tags($vm), [self::SUSPENDED_TAG]));
        rescue(fn () => $this->proxmox->put('/access/users/'.rawurlencode($this->userId($service)), ['enable' => true]), report: false);

        if (($vm['status'] ?? null) !== 'running') {
            $this->proxmox->waitForTask($vm['node'], $this->proxmox->post($this->vmPath($vm['node'], $vm['vmid']).'/status/start'));
        }
    }

    /** Deletes the machine with all its disks, its Proxmox user, and gives its address back to the pool. */
    public function terminateAccount(Service $service): void
    {
        $this->destroy($service, $this->vm($service));
    }

    /**
     * Takes the new template's CPU and memory, and grows the disk to its size (a disk never shrinks). Proxmox applies
     * CPU and memory at the next shutdown and start, unless the machine has hotplug for them.
     */
    public function changePackage(Service $service, string $package): void
    {
        $vm = $this->vm($service);
        $template = $this->template($package);
        $wanted = (array) $this->proxmox->get($this->vmPath($template['node'], $template['vmid']).'/config');
        $current = (array) $this->proxmox->get($this->vmPath($vm['node'], $vm['vmid']).'/config');

        $this->proxmox->put($this->vmPath($vm['node'], $vm['vmid']).'/config', array_filter([
            'cores' => $wanted['cores'] ?? null,
            'sockets' => $wanted['sockets'] ?? null,
            'memory' => $wanted['memory'] ?? null,
        ], fn ($value) => $value !== null));

        [$wantedDisk, $wantedSize] = self::bootDisk($wanted);
        [$disk, $size] = self::bootDisk($current);

        if ($disk !== null && $wantedSize !== null && $size !== null && $wantedSize > $size) {
            $this->proxmox->waitForTask($vm['node'], $this->proxmox->put($this->vmPath($vm['node'], $vm['vmid']).'/resize', [
                'disk' => $disk,
                'size' => intdiv($wantedSize, 1024 ** 2).'M',
            ]));
        }
    }

    /**
     * The client's Proxmox login, and the cloud-init user's password for the next start. A running machine gets it at
     * once through the QEMU guest agent when it has one; without it the new password applies at the next restart.
     */
    public function changePassword(Service $service, string $password): void
    {
        $vm = $this->vm($service);

        $this->replaceUser($service, $vm['vmid'], $password);
        $this->proxmox->put($this->vmPath($vm['node'], $vm['vmid']).'/config', ['cipassword' => $password], secrets: [$password]);

        if (($vm['status'] ?? null) === 'running') {
            rescue(fn () => $this->proxmox->post($this->vmPath($vm['node'], $vm['vmid']).'/agent/set-user-password', [
                'username' => config('gaiacp.proxmox.ci_user') ?: 'root',
                'password' => $password,
            ], secrets: [$password]), report: false);
        }
    }

    /**
     * Proxmox has no one-time login link for another user, so this is its web interface: the client signs in with
     * their username, the "Proxmox VE authentication server" realm and their password, and sees only their VPS.
     */
    public function loginUrl(Service $service, string $clientIp): string
    {
        $this->vm($service);

        return sprintf('https://%s:%d/', $this->server->hostname, $this->server->port);
    }

    public function adminLoginUrl(string $clientIp): string
    {
        return sprintf('https://%s:%d/', $this->server->hostname, $this->server->port);
    }

    /** The disk's size as the limit; KVM reports no disk use or monthly traffic without more set-up. */
    public function usage(Service $service): AccountUsage
    {
        $vm = $this->vm($service);

        return new AccountUsage(
            diskUsed: null,
            diskLimit: isset($vm['maxdisk']) && $vm['maxdisk'] > 0 ? (int) $vm['maxdisk'] : null,
            bandwidthUsed: null,
            bandwidthLimit: null,
        );
    }

    public function isRunning(Service $service): bool
    {
        return ($this->vm($service)['status'] ?? null) === 'running';
    }

    public function power(Service $service, string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new ProvisioningException("Unknown power action {$action}.");
        }

        $vm = $this->vm($service);

        if (in_array(self::SUSPENDED_TAG, $this->tags($vm), true)) {
            throw new ProvisioningException("The VPS of {$service->username} is suspended on {$this->server->name}.");
        }

        $this->proxmox->waitForTask($vm['node'], $this->proxmox->post($this->vmPath($vm['node'], $vm['vmid'])."/status/{$action}"));
    }

    public function clientsControlPower(): bool
    {
        return (bool) config('gaiacp.proxmox.client_power');
    }

    /**
     * The boot disk of a VM config and its size in bytes: the first disk in `boot` order (else scsi0, virtio0, sata0,
     * ide0), never a CD-ROM or the cloud-init drive.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: ?string, 1: ?int}
     */
    public static function bootDisk(array $config): array
    {
        preg_match('/order=([^,]+)/', (string) ($config['boot'] ?? ''), $order);
        $candidates = [...explode(';', $order[1] ?? ''), 'scsi0', 'virtio0', 'sata0', 'ide0'];

        foreach ($candidates as $disk) {
            $value = (string) ($config[$disk] ?? '');

            if ($value === '' || str_contains($value, 'media=cdrom') || str_contains($value, 'cloudinit')) {
                continue;
            }

            if (preg_match('/(?:^|,)size=(\d+(?:\.\d+)?)([KMGT]?)/', $value, $m)) {
                $power = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4][$m[2]];

                return [$disk, (int) round((float) $m[1] * 1024 ** $power)];
            }
        }

        return [null, null];
    }

    /** @param  array{vmid: int, node: string, status?: string}  $vm */
    private function destroy(Service $service, array $vm): void
    {
        $this->stopIfRunning($vm);
        $this->proxmox->waitForTask($vm['node'], $this->proxmox->delete($this->vmPath($vm['node'], $vm['vmid']), [
            'purge' => 1,
            'destroy-unreferenced-disks' => 1,
        ]));
        rescue(fn () => $this->proxmox->delete('/access/users/'.rawurlencode($this->userId($service))), report: false);
        ProxmoxAddress::release($service);
    }

    /** @param  array{vmid: int, node: string, status?: string}  $vm */
    private function stopIfRunning(array $vm): void
    {
        $status = $vm['status'] ?? ((array) $this->proxmox->get($this->vmPath($vm['node'], $vm['vmid']).'/status/current'))['status'] ?? null;

        if ($status === 'running') {
            $this->proxmox->waitForTask($vm['node'], $this->proxmox->post($this->vmPath($vm['node'], $vm['vmid']).'/status/stop'));
        }
    }

    /** The client's Proxmox user, made again with the password, with the role on this machine only. */
    private function replaceUser(Service $service, int $vmid, string $password): void
    {
        $userId = $this->userId($service);

        rescue(fn () => $this->proxmox->delete('/access/users/'.rawurlencode($userId)), report: false);

        $this->proxmox->post('/access/users', array_filter([
            'userid' => $userId,
            'password' => $password,
            'email' => $service->client?->email,
            'comment' => 'GaiaCP: '.($service->domain ?: "service #{$service->id}"),
        ]), secrets: [$password]);

        $this->proxmox->put('/access/acl', [
            'path' => "/vms/{$vmid}",
            'users' => $userId,
            'roles' => config('gaiacp.proxmox.client_role') ?: 'PVEVMUser',
            'propagate' => false,
        ]);
    }

    /** ipconfig0 from the server's pool; DHCP when it has none, an error when they are all taken. */
    private function ipConfig(Service $service): string
    {
        $address = ProxmoxAddress::claim($service);

        if ($address) {
            return $address->ipConfig();
        }

        if (ProxmoxAddress::hasPool($this->server->id)) {
            throw new ProvisioningException("{$this->server->name} has no free IP address left: add more under Settings → Proxmox.");
        }

        return 'ip=dhcp';
    }

    /** @return array{vmid: int, node: string, name: string} */
    private function template(string $name): array
    {
        $template = collect($this->vms())->first(fn (array $vm) => ! empty($vm['template']) && ($vm['name'] ?? null) === $name)
            ?? throw new ProvisioningException("There is no template {$name} on {$this->server->name}.");

        return ['vmid' => (int) $template['vmid'], 'node' => (string) $template['node'], 'name' => $name];
    }

    /** @return array{vmid: int, node: string, status?: string, tags?: string, name?: string, maxdisk?: int} */
    private function vm(Service $service): array
    {
        return $this->findVm($service)
            ?? throw new ProvisioningException("There is no VPS for {$service->username} on {$this->server->name}.");
    }

    /** @return array<string, mixed>|null */
    private function findVm(Service $service): ?array
    {
        $username = strtolower($this->username($service));

        $vm = collect($this->vms())->first(fn (array $vm) => empty($vm['template']) && $this->usernameOf($vm) === $username);

        return $vm === null ? null : ['vmid' => (int) $vm['vmid'], 'node' => (string) $vm['node']] + $vm;
    }

    /** @return list<array<string, mixed>> the cluster's KVM machines and templates */
    private function vms(): array
    {
        return array_values(array_filter((array) $this->proxmox->get('/cluster/resources', ['type' => 'vm']), fn ($vm) => is_array($vm) && ($vm['type'] ?? null) === 'qemu'));
    }

    /** @param  array<string, mixed>  $vm */
    private function usernameOf(array $vm): ?string
    {
        foreach ($this->tags($vm) as $tag) {
            if (str_starts_with($tag, self::USER_TAG) && strlen($tag) > strlen(self::USER_TAG)) {
                return substr($tag, strlen(self::USER_TAG));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $vm
     * @return list<string>
     */
    private function tags(array $vm): array
    {
        return array_values(array_filter(preg_split('/[;,\s]+/', strtolower((string) ($vm['tags'] ?? '')))));
    }

    /**
     * @param  array{vmid: int, node: string}  $vm
     * @param  array<string>  $tags
     */
    private function setTags(array $vm, array $tags): void
    {
        $this->proxmox->put($this->vmPath($vm['node'], $vm['vmid']).'/config', ['tags' => $this->tagList($tags)]);
    }

    /** @param  array<string>  $tags */
    private function tagList(array $tags): string
    {
        return implode(';', array_values(array_unique($tags)));
    }

    /** The machine's name: the domain in punycode when it is a valid host name, else the username. */
    private function vmName(Service $service): string
    {
        $domain = $service->domain ? DomainName::toAscii($service->domain) : '';

        return preg_match('/^(?=.{1,63}$)[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $domain) ? strtolower($domain) : strtolower($this->username($service));
    }

    private function vmPath(string $node, int $vmid): string
    {
        return '/nodes/'.rawurlencode($node)."/qemu/{$vmid}";
    }

    private function userId(Service $service): string
    {
        return strtolower($this->username($service)).'@'.self::REALM;
    }

    private function username(Service $service): string
    {
        return $service->username
            ?: throw new ProvisioningException('This service has no Proxmox username.');
    }
}

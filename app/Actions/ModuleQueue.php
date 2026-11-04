<?php

namespace App\Actions;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\ModuleQueueItem;
use App\Models\Registrar;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Services → Module queue, like WHMCS's: a panel or registrar action that failed (setting up a pending service,
 * suspend, unsuspend, terminate, a domain renewal) is kept as an open item until it is retried with success, marked
 * resolved by an admin, or no longer needed (the service moved on). Items come from the `provisioning.failed`
 * activity log entries, so every caller is covered; a later success of the same action closes them too.
 *
 * gaiacp:retry-module-queue (every 15 minutes) retries the safe ones by itself (set up, unsuspend, suspend for
 * non-payment) up to Settings → Automation → Retry failed actions times, then rings the bell once. Terminate and
 * domain renewal are only retried by an admin.
 */
class ModuleQueue
{
    /** Minutes between two automatic tries of the same item. */
    public const RETRY_MINUTES = 15;

    /** provisioning.failed `event`s of a pending service's set up */
    private const SETUP_EVENTS = ['account.created', 'domain.registered', 'domain.transfer_requested', 'ssl.ordered'];

    /** the other provisioning.failed `event`s kept in the queue */
    private const EVENTS = [
        'account.suspended' => ModuleQueueItem::SUSPEND,
        'account.unsuspended' => ModuleQueueItem::UNSUSPEND,
        'account.terminated' => ModuleQueueItem::TERMINATE,
        'domain.renewed' => ModuleQueueItem::RENEW,
    ];

    /** activity log events that mean the action was done (by a retry, a click elsewhere or the daily run) */
    private const DONE = [
        'service.activated' => [ModuleQueueItem::SETUP],
        'account.created' => [ModuleQueueItem::SETUP],
        'domain.activated' => [ModuleQueueItem::SETUP],
        'domain.registered' => [ModuleQueueItem::SETUP],
        'domain.transfer_requested' => [ModuleQueueItem::SETUP],
        'ssl.ordered' => [ModuleQueueItem::SETUP],
        'service.suspended' => [ModuleQueueItem::SUSPEND, ModuleQueueItem::UNSUSPEND],
        'service.unsuspended' => [ModuleQueueItem::UNSUSPEND, ModuleQueueItem::SUSPEND],
        'service.terminated' => [ModuleQueueItem::SETUP, ModuleQueueItem::SUSPEND, ModuleQueueItem::UNSUSPEND, ModuleQueueItem::TERMINATE, ModuleQueueItem::RENEW],
        'domain.renewed' => [ModuleQueueItem::RENEW],
    ];

    /** True while the queue itself retries: its failures go to the item, not the bell again. */
    private static bool $retrying = false;

    public function __construct(
        private SetUpService $setUp,
        private ServiceLifecycle $lifecycle,
        private DomainLifecycle $domains,
    ) {}

    public static function retrying(): bool
    {
        return self::$retrying;
    }

    /** Called for every new activity log row: a failure opens (or updates) an item, a success closes it. */
    public static function fromLog(ActivityLog $log): void
    {
        if (! $log->service_id || ($log->event !== 'provisioning.failed' && ! isset(self::DONE[$log->event]))) {
            return;
        }

        // A queue that can't be written never undoes what was logged.
        try {
            if ($log->event !== 'provisioning.failed') {
                ModuleQueueItem::query()->open()->where('service_id', $log->service_id)->whereIn('action', self::DONE[$log->event])
                    ->update(['resolved_at' => now(), 'resolution' => ModuleQueueItem::DONE, 'resolved_by' => $log->user_id]);

                return;
            }

            if ($log->properties['retry'] ?? false) {
                return; // retry() keeps its own item up to date
            }

            $service = $log->service;
            $event = $log->properties['event'] ?? null;
            $action = in_array($event, self::SETUP_EVENTS, true)
                ? ($service?->status === ServiceStatus::Pending ? ModuleQueueItem::SETUP : null)
                : (self::EVENTS[$event] ?? null);

            if ($service === null || $action === null) {
                return; // a call an admin made and saw fail (password, nameservers, login…): nothing to retry later
            }

            $error = Str::after($log->description, 'failed: ');
            $item = ModuleQueueItem::query()->open()->where('service_id', $service->id)->where('action', $action)->first();

            if ($item) {
                $item->update([
                    'error' => $error,
                    'attempts' => $item->attempts + 1,
                    'last_attempt_at' => now(),
                    'reason' => $log->properties['reason'] ?? $item->reason,
                ]);

                return;
            }

            ModuleQueueItem::query()->create([
                'service_id' => $service->id,
                'action' => $action,
                'module' => self::module($service, $log),
                'reason' => $log->properties['reason'] ?? null,
                'error' => $error,
                'last_attempt_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Try the item's action again; true when it worked (or is no longer needed) and the item is closed. */
    public function retry(ModuleQueueItem $item, bool $auto = false): bool
    {
        if ($item->resolved_at !== null) {
            return true;
        }

        if (! $this->stillNeeded($item)) {
            $this->close($item, ModuleQueueItem::NOT_NEEDED);

            return true;
        }

        self::$retrying = true;

        try {
            $this->run($item);
        } catch (Throwable $e) {
            if (! $e instanceof ProvisioningException) {
                report($e);
            }

            $item->update([
                'error' => $e->getMessage(),
                'attempts' => $item->attempts + 1,
                'auto_attempts' => $item->auto_attempts + ($auto ? 1 : 0),
                'last_attempt_at' => now(),
            ]);

            return false;
        } finally {
            self::$retrying = false;
        }

        $this->close($item->refresh(), ModuleQueueItem::RETRIED, auth()->id());

        return true;
    }

    /** Mark resolved by hand: the admin fixed it on the server, or it doesn't matter any more. */
    public function markResolved(ModuleQueueItem $item): void
    {
        if ($item->resolved_at !== null) {
            return;
        }

        $this->close($item, ModuleQueueItem::MARKED, auth()->id());
        ActivityLog::record('module_queue.resolved', "Module queue: {$item->action} marked resolved", $item->service);
    }

    /**
     * The automatic run: close what is no longer needed, then retry the safe items that waited RETRY_MINUTES since
     * their last try, up to $limit times each; the one that still fails after that rings the bell once.
     *
     * @return array{retried: int, fixed: int, gave_up: int, closed: int}
     */
    public function runDue(int $limit): array
    {
        $result = ['retried' => 0, 'fixed' => 0, 'gave_up' => 0, 'closed' => $this->sweep()];

        if ($limit < 1) {
            return $result;
        }

        foreach ($this->due($limit) as $item) {
            $result['retried']++;

            if ($this->retry($item, auto: true)) {
                $result['fixed']++;

                continue;
            }

            if ($item->auto_attempts >= $limit) {
                $item->update(['gave_up_at' => now()]);
                $result['gave_up']++;
                ActivityLog::record('module_queue.gave_up', "{$item->action} still fails after {$item->auto_attempts} automatic tries: {$item->error}", $item->service,
                    properties: ['action' => $item->action, 'tries' => $item->auto_attempts, 'error' => Str::limit($item->error, 300)]);
            }
        }

        return $result;
    }

    /** @return list<ModuleQueueItem> the open items the automatic run would retry now */
    public function due(int $limit): array
    {
        return ModuleQueueItem::query()->open()->with('service')
            ->whereNull('gave_up_at')
            ->where('auto_attempts', '<', $limit)
            ->where('last_attempt_at', '<=', now()->subMinutes(self::RETRY_MINUTES))
            ->orderBy('id')
            ->get()
            ->filter(fn (ModuleQueueItem $item) => self::retriesByItself($item))
            ->values()
            ->all();
    }

    /** Close the open items whose service moved on (set up, paid, terminated…); returns how many. */
    public function sweep(): int
    {
        $closed = 0;

        foreach (ModuleQueueItem::query()->open()->with('service')->get() as $item) {
            if (! $this->stillNeeded($item)) {
                $this->close($item, ModuleQueueItem::NOT_NEEDED);
                $closed++;
            }
        }

        return $closed;
    }

    /** Set up, unsuspend and a suspension for non-payment are retried by the cron; terminate and renew only by an admin. */
    public static function retriesByItself(ModuleQueueItem $item): bool
    {
        return in_array($item->action, [ModuleQueueItem::SETUP, ModuleQueueItem::UNSUSPEND], true)
            || ($item->action === ModuleQueueItem::SUSPEND && $item->reason === Service::SUSPENDED_OVERDUE);
    }

    /** Whether the action still has to be done, read from the service as it is now. */
    public function stillNeeded(ModuleQueueItem $item): bool
    {
        $service = $item->service;

        if ($service === null) {
            return false;
        }

        $live = in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);

        return match ($item->action) {
            ModuleQueueItem::SETUP => $service->status === ServiceStatus::Pending,
            ModuleQueueItem::SUSPEND => $live && ($item->reason !== Service::SUSPENDED_OVERDUE || $this->stillOverdue($service)),
            ModuleQueueItem::UNSUSPEND => $live && ! ($service->status === ServiceStatus::Suspended && $service->suspension_reason === Service::SUSPENDED_OVERDUE && $this->stillOverdue($service)),
            ModuleQueueItem::TERMINATE => $live,
            ModuleQueueItem::RENEW => $live && $service->type === ServiceType::Domain && $this->expiresBeforePaidUntil($service),
            default => false,
        };
    }

    private function run(ModuleQueueItem $item): void
    {
        $service = $item->service;

        match ($item->action) {
            ModuleQueueItem::SETUP => $this->setUp->handle($service),
            ModuleQueueItem::SUSPEND => $this->lifecycle->suspend($service, (string) $item->reason),
            ModuleQueueItem::UNSUSPEND => $this->lifecycle->unsuspend($service),
            ModuleQueueItem::TERMINATE => $this->lifecycle->terminate($service),
            ModuleQueueItem::RENEW => $this->renew($service),
        };
    }

    /** A renewal is not idempotent at the registrar: read the domain first, and renew only if it still ends too soon. */
    private function renew(Service $service): void
    {
        if ($this->domains->readsRegistrar($service)) {
            $this->domains->sync($service);
            $service->refresh();
        }

        if ($this->expiresBeforePaidUntil($service)) {
            $this->domains->renew($service);
        }
    }

    private function expiresBeforePaidUntil(Service $service): bool
    {
        $expires = $service->domainDetails?->expires_at;

        return $expires === null || $expires->lessThan($service->next_due_date);
    }

    /** Overdue and not exempt from auto-suspension ("Do not suspend until"). */
    private function stillOverdue(Service $service): bool
    {
        return $service->isOverdue() && ($service->override_suspend_until === null || $service->override_suspend_until->lessThan(today()));
    }

    private function close(ModuleQueueItem $item, string $resolution, ?int $by = null): void
    {
        $item->update(['resolved_at' => now(), 'resolution' => $resolution, 'resolved_by' => $by]);
    }

    /** The server, registrar or certificate provider the call went to, as the queue shows it. */
    private static function module(Service $service, ActivityLog $log): ?string
    {
        return match ($service->type) {
            ServiceType::Domain => Registrar::query()->find($log->properties['registrar_id'] ?? null)?->name
                ?? rescue(fn () => $service->registration()->registrarAccount()?->name, null, false),
            ServiceType::Ssl => $service->certificateDetails?->provider,
            default => $service->server?->name,
        };
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One failed panel or registrar action of a service, open until retried with success, marked resolved or no longer
 * needed (App\Actions\ModuleQueue). At most one open item per service and action.
 *
 * @property CarbonImmutable $last_attempt_at
 * @property CarbonImmutable|null $gave_up_at
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable(['service_id', 'action', 'module', 'reason', 'error', 'attempts', 'auto_attempts', 'last_attempt_at', 'gave_up_at', 'resolved_at', 'resolution', 'resolved_by'])]
class ModuleQueueItem extends Model
{
    use StoresTimesInUtc;

    protected $table = 'module_queue';

    /** Set up a pending service: create the hosting account, register / transfer the domain, order the certificate. */
    public const SETUP = 'setup';

    public const SUSPEND = 'suspend';

    public const UNSUSPEND = 'unsuspend';

    public const TERMINATE = 'terminate';

    public const RENEW = 'renew';

    /** How an item was closed. */
    public const RETRIED = 'retried';

    public const MARKED = 'marked';

    public const NOT_NEEDED = 'not_needed';

    /** The same action worked another way (Complete order, a button on the service, the daily run). */
    public const DONE = 'done';

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'immutable_datetime',
            'gave_up_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<ModuleQueueItem> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** The action in the current language, as the queue shows it. */
    public function actionLabel(): string
    {
        return match ($this->action) {
            self::SETUP => match ($this->service?->type?->value) {
                'domain' => __('Register or transfer the domain'),
                'ssl' => __('Order the certificate'),
                default => __('Create the account'),
            },
            self::SUSPEND => __('Suspend'),
            self::UNSUSPEND => __('Unsuspend'),
            self::TERMINATE => __('Terminate'),
            self::RENEW => __('Renew the domain'),
            default => $this->action,
        };
    }

    public function resolutionLabel(): ?string
    {
        return match ($this->resolution) {
            self::RETRIED => __('Retried with success'),
            self::MARKED => __('Marked resolved'),
            self::NOT_NEEDED => __('No longer needed'),
            self::DONE => __('Done another way'),
            default => null,
        };
    }
}

<?php

namespace App\Models;

use App\Actions\AddAddon;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Support\DomainName;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * options: the product options picked (App\Support\ProductOptions::select()), each with its price then; price_cents
 * already includes them.
 *
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $next_due_date
 */
#[Fillable([
    'client_id', 'order_id', 'coupon_id', 'parent_service_id', 'type', 'product_id', 'server_id', 'label', 'domain', 'username', 'password', 'package', 'options',
    'price_cents', 'first_payment_cents', 'new_price_cents', 'new_price_from', 'currency', 'billing_cycle', 'start_date', 'next_due_date',
    'status', 'suspension_reason', 'notes', 'auto_setup', 'override_suspend_until', 'auto_terminate', 'auto_terminate_reason',
    'auto_renew', 'client_visible',
])]
#[Hidden(['password'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /** Value of suspension_reason when the service was suspended for non-payment. */
    public const SUSPENDED_OVERDUE = 'overdue';

    protected static function booted(): void
    {
        // "Show in client area" comes from the product; on for a service without one.
        static::creating(function (Service $service) {
            if (! array_key_exists('client_visible', $service->getAttributes())) {
                $service->client_visible = $service->product?->client_visible ?? true;
            }
        });

        // A domain needs no label of its own: it is called by its name.
        static::saving(function (Service $service) {
            if (filled($service->domain) && $service->isDirty('domain')) {
                $service->domain = DomainName::normalize($service->domain);
            }

            if ($service->type === ServiceType::Domain && blank($service->label) && filled($service->domain)) {
                $service->label = $service->domain;
            }
        });

        // Set to end at its due date: the cycles it won't start are owed no more. Terminated or cancelled by hand
        // (the Account menu or the status field): none of its unpaid cycles is owed, so it gets no more reminders.
        // One that ended through Auto-terminate keeps owing an overdue cycle, as it did when it was set to end.
        static::updated(function (Service $service) {
            if ($service->wasChanged('auto_terminate') && $service->auto_terminate) {
                Invoice::withdraw($service);
            }

            if ($service->wasChanged('status') && ! $service->auto_terminate
                && in_array($service->status, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true)) {
                Invoice::withdraw($service, overdueToo: true, reason: 'it was '.strtolower($service->status->name));
            }

            // Its add-ons are suspended, unsuspended, set to end and ended with it.
            if ($service->wasChanged(['status', 'auto_terminate'])) {
                AddAddon::follow($service);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'status' => ServiceStatus::class,
            'billing_cycle' => BillingCycle::class,
            'auto_setup' => AutoSetup::class,
            'password' => 'encrypted',
            'price_cents' => 'integer',
            'first_payment_cents' => 'integer',
            'new_price_cents' => 'integer',
            'new_price_from' => 'immutable_date',
            'start_date' => 'immutable_date',
            'next_due_date' => 'immutable_date',
            'override_suspend_until' => 'immutable_date',
            'auto_terminate' => 'boolean',
            'client_visible' => 'boolean',
            'options' => 'array',
        ];
    }

    /**
     * A domain's "auto renew" (as in WHMCS) is the inverse of auto_terminate: off, it gets no more
     * payment notices or renewal emails and ends at its due date.
     */
    protected function autoRenew(): Attribute
    {
        return Attribute::make(
            get: fn () => ! $this->auto_terminate,
            set: fn ($value) => ['auto_terminate' => ! $value],
        );
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Service, $this> for an add-on: the service it was added to */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'parent_service_id');
    }

    /** @return HasMany<Service, $this> its add-ons (App\Actions\AddAddon) */
    public function addons(): HasMany
    {
        return $this->hasMany(Service::class, 'parent_service_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Coupon, $this> the coupon that lowered its first payment, or its price too when recurring */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasOne<Domain, $this> Registrar details of a domain service. */
    public function domainDetails(): HasOne
    {
        return $this->hasOne(Domain::class);
    }

    /** The registrar details of a domain service, created on first use. */
    public function registration(): Domain
    {
        return $this->domainDetails ?? tap($this->domainDetails()->create(), fn (Domain $domain) => $this->setRelation('domainDetails', $domain));
    }

    /** @return HasOne<Certificate, $this> Provider details of an SSL certificate service. */
    public function certificateDetails(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /** The provider details of an SSL certificate service, created on first use. */
    public function certificate(): Certificate
    {
        return $this->certificateDetails ?? tap($this->certificateDetails()->create(), fn (Certificate $certificate) => $this->setRelation('certificateDetails', $certificate));
    }

    /** What the first payment costs: a domain's registration or transfer price, else the price per cycle. */
    public function firstPaymentCents(): int
    {
        return $this->first_payment_cents ?? $this->price_cents;
    }

    /** The price owed for the period starting on $due: a scheduled new price (App\Actions\ChangePrice) once it applies. */
    public function priceOn(CarbonInterface $due): int
    {
        return $this->new_price_from !== null && $this->new_price_cents !== null && ! $due->lt($this->new_price_from)
            ? $this->new_price_cents
            : $this->price_cents;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasMany<UpgradeRequest, $this> the client's requests to move it to another product (App\Actions\UpgradeRequests) */
    public function upgradeRequests(): HasMany
    {
        return $this->hasMany(UpgradeRequest::class);
    }

    /** @return HasMany<CancellationRequest, $this> the client's requests to cancel it (App\Actions\CancellationRequests) */
    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** @param Builder<Service> $query */
    public function scopeBillable(Builder $query): void
    {
        $query->whereIn('status', ServiceStatus::billable());
    }

    /** @param Builder<Service> $query  Shown to its client in the client area ("Show in client area"). */
    public function scopeClientVisible(Builder $query): void
    {
        $query->where('client_visible', true);
    }

    public function isOverdue(?CarbonInterface $today = null): bool
    {
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();

        return in_array($this->status, ServiceStatus::billable(), true)
            && $this->next_due_date->lessThan($today);
    }

    /**
     * A one-time service (BillingCycle::OneTime) that is set up and paid has nothing more to bill: it is Completed.
     * Called once it is set up (SetUpService) and after each payment (RecordPayment); safe to call again.
     */
    public function completeIfPaidOnce(): void
    {
        if ($this->billing_cycle !== BillingCycle::OneTime || $this->status !== ServiceStatus::Active || ! $this->payments()->exists()) {
            return;
        }

        $this->update(['status' => ServiceStatus::Completed]);
        ActivityLog::record('service.completed', "{$this->label} paid once: completed", $this);
    }

    /** Years to renew a domain for: one billing cycle, at least a year. */
    public function registrationYears(): int
    {
        return max(1, intdiv($this->billing_cycle->months(), 12));
    }

    /** Day of month renewals are anchored to, so month-end dates don't drift. */
    public function anchorDay(): int
    {
        return $this->start_date->day;
    }
}

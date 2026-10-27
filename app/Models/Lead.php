<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prospective client (Clients → Leads, App\Actions\Leads): who they are, where they came from, what they are
 * after, and when to contact them next (the bell rings that morning). Converting one makes it a client, or links it
 * to the client who already has its email; the lead stays as Won and points to them.
 *
 * @property-read string $name
 * @property CarbonImmutable|null $next_contact_on
 * @property CarbonImmutable|null $closed_at
 */
#[Fillable(['first_name', 'last_name', 'company', 'email', 'phone', 'country', 'source', 'status', 'lost_reason', 'notes', 'assigned_to', 'next_contact_on', 'follow_up_sent_on', 'client_id', 'closed_at'])]
class Lead extends Model
{
    protected $attributes = [
        'status' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'next_contact_on' => 'immutable_date',
            'follow_up_sent_on' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** First and last name, as for clients. */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    /** The name with the company, for lists and the bell. */
    public function displayName(): string
    {
        return $this->company ? "{$this->name} ({$this->company})" : $this->name;
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', LeadStatus::open());
    }

    /** Open leads whose next contact is today or already past. @param Builder<self> $query */
    public function scopeToContact(Builder $query): void
    {
        $query->open()->whereNotNull('next_contact_on')->whereDate('next_contact_on', '<=', today());
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<LeadNote, $this> */
    public function leadNotes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest('id');
    }
}

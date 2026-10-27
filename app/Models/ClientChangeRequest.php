<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Details a client asked to change from the client area, applied only when an admin approves
 * (App\Actions\ClientChangeRequests). `fields` holds field => [value when asked, value asked for].
 *
 * @property array<string, array{0: mixed, 1: mixed}> $fields
 * @property CarbonImmutable|null $decided_at
 */
#[Fillable(['client_id', 'fields', 'status', 'decided_by', 'decided_at'])]
class ClientChangeRequest extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'status' => ChangeRequestStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param Builder<ClientChangeRequest> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ChangeRequestStatus::Pending);
    }

    /** @return array<string, mixed> field => value asked for */
    public function requestedValues(): array
    {
        return array_map(fn (array $change) => $change[1], $this->fields);
    }
}

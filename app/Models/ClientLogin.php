<?php

namespace App\Models;

use App\ClientSessions\ClientSessions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in to the client area, or a failed try with the client's email (App\ClientSessions\ClientSessions). Never
 * edited; deleted after ClientSessions::KEEP_DAYS.
 *
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'client_id', 'succeeded', 'ip_address', 'user_agent', 'created_at'])]
class ClientLogin extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function device(): string
    {
        return ClientSessions::device($this->user_agent);
    }
}

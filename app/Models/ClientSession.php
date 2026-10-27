<?php

namespace App\Models;

use App\ClientSessions\ClientSessions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser signed in to the client area (App\ClientSessions\ClientSessions). Deleting the row signs that browser out
 * on its next request.
 *
 * @property CarbonImmutable $last_active_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'token_hash', 'ip_address', 'user_agent', 'last_active_at'])]
class ClientSession extends Model
{
    protected function casts(): array
    {
        return [
            'last_active_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Browser and system, e.g. "Chrome on Windows". */
    public function device(): string
    {
        return ClientSessions::device($this->user_agent);
    }

    /** Whether it is the browser this request comes from. */
    public function isCurrent(): bool
    {
        return $this->token_hash === ClientSessions::currentHash();
    }
}

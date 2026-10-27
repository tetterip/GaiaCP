<?php

namespace App\Models;

use App\ClientSessions\ClientSessions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser where an admin turned on push notifications (Profile), sent to by App\Push\WebPush. */
#[Fillable(['user_id', 'endpoint_hash', 'endpoint', 'public_key', 'auth_token', 'user_agent', 'last_sent_at'])]
#[Hidden(['public_key', 'auth_token'])]
class PushSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'public_key' => 'encrypted',
            'auth_token' => 'encrypted',
            'last_sent_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): string
    {
        return ClientSessions::device($this->user_agent);
    }
}

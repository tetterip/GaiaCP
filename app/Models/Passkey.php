<?php

namespace App\Models;

use App\ClientSessions\ClientSessions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A passkey a login added from its profile (App\SignIn\Passkeys): only the public key is kept, never a secret. */
#[Fillable(['user_id', 'name', 'credential_id', 'public_key', 'sign_count', 'user_agent', 'last_used_at'])]
#[Hidden(['public_key'])]
class Passkey extends Model
{
    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The browser it was added from, e.g. "Chrome on Android". */
    public function device(): string
    {
        return ClientSessions::device($this->user_agent);
    }
}

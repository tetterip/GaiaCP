<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One client a message to clients is emailed to, fixed when it is sent (App\Actions\SendClientMessages): pending until
 * sent_at or failed_at is set, so each gets it at most once.
 *
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 */
#[Fillable(['client_message_id', 'client_id', 'email', 'sent_at', 'failed_at', 'error'])]
class ClientMessageRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClientMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ClientMessage::class, 'client_message_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @param  Builder<ClientMessageRecipient>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('sent_at')->whereNull('failed_at');
    }
}

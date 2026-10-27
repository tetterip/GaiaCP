<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One message in a ticket: the client's, an admin's reply, or an admins' internal note (never shown to the client). */
#[Fillable(['ticket_id', 'user_id', 'from_client', 'internal', 'body', 'email_message_id'])]
class TicketMessage extends Model
{
    protected function casts(): array
    {
        return [
            'from_client' => 'boolean',
            'internal' => 'boolean',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TicketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /** @param Builder<TicketMessage> $query */
    public function scopePublic(Builder $query): void
    {
        $query->where('internal', false);
    }

    /** Who wrote it, as the thread shows it: the client's name, or the admin's. */
    public function authorName(): string
    {
        if ($this->from_client) {
            return $this->ticket->client?->name ?? ($this->user?->name ?? $this->ticket->requesterName());
        }

        return $this->user?->name ?? __('Support');
    }
}

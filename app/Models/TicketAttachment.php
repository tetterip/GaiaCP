<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/** A file sent with a ticket message, on the private "local" disk (App\Http\Controllers\TicketAttachmentController). */
#[Fillable(['ticket_message_id', 'path', 'name', 'size', 'mime'])]
class TicketAttachment extends Model
{
    protected static function booted(): void
    {
        static::deleted(fn (TicketAttachment $attachment) => Storage::disk('local')->delete($attachment->path));
    }

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<TicketMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    public function readableSize(): string
    {
        return Number::fileSize($this->size, precision: 1);
    }
}

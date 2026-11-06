<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/** A file sent with a ticket message, on the private "local" disk (App\Http\Controllers\TicketAttachmentController). */
#[Fillable(['ticket_message_id', 'path', 'name', 'size', 'mime'])]
class TicketAttachment extends Model
{
    use StoresTimesInUtc;

    protected static function booted(): void
    {
        static::deleted(fn (TicketAttachment $attachment) => Storage::disk('local')->delete($attachment->path));
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'removed_at' => 'datetime'];
    }

    /** @return BelongsTo<TicketMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    /** Its file was deleted to free disk space (Settings → Support → Delete files of closed tickets after). */
    public function removed(): bool
    {
        return $this->removed_at !== null;
    }

    public function readableSize(): string
    {
        return Number::fileSize($this->size, precision: 1);
    }
}

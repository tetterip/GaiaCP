<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A message for a chat plugin (App\Chat\ChatPlugin), sent by gaiacp:send-chat-messages (App\Chat\ChatSender). */
#[Fillable(['channel', 'event', 'body', 'status', 'attempts', 'next_attempt_at', 'error'])]
class ChatMessage extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $attributes = [
        'status' => self::PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }
}

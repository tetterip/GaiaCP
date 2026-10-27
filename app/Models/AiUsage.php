<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One answer of the AI assistant and its tokens (App\Ai\Assistant), for the monthly limit; the texts are not kept. */
#[Fillable(['user_id', 'feature', 'provider', 'model', 'input_tokens', 'output_tokens', 'created_at'])]
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeThisMonth(Builder $query): void
    {
        $query->where('created_at', '>=', now()->startOfMonth());
    }

    /** Tokens sent and received this calendar month, by every admin. */
    public static function tokensThisMonth(): int
    {
        return (int) self::query()->thisMonth()->sum('input_tokens') + (int) self::query()->thisMonth()->sum('output_tokens');
    }
}

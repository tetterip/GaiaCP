<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An IP address kept out of the login, sign up and forgot password pages of both panels, made by
 * App\Support\LoginSecurity after too many failed logins or added by an admin (Settings → Banned IPs).
 */
#[Fillable(['ip', 'reason', 'attempts', 'expires_at', 'user_id'])]
class IpBan extends Model
{
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** Why, in the current language: the admin's reason, else the failed logins. */
    public function reasonText(): string
    {
        return $this->reason ?? ($this->attempts !== null
            ? __('Failed login attempts: :count', ['count' => $this->attempts])
            : __('Banned by an administrator'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

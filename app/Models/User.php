<?php

namespace App\Models;

use App\Actions\ClientAreaAccess;
use App\Actions\ClientSignup;
use App\Enums\AdminArea;
use App\Enums\UserRole;
use App\Support\Locales;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'locale', 'theme', 'password', 'role', 'client_id', 'super_admin', 'admin_areas', 'active', 'system_emails', 'panel_notifications'])]
#[Hidden(['password', 'remember_token'])]
/**
 * Two-factor authentication is opt-in per user from the profile page (authenticator app plus
 * recovery codes); `php artisan gaiacp:disable-2fa <email>` turns it off for a locked-out user.
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /** @var array<string, mixed> New users are active admins, also before they are read back from the database. */
    protected $attributes = [
        'role' => 'admin',
        'super_admin' => false,
        'active' => true,
        'system_emails' => true,
        'panel_notifications' => true,
    ];

    /**
     * Active admins only: there is no public registration, admins are added under Settings → Admin users
     * (or with `php artisan make:filament-user`). A deactivated admin is refused at login and on their next request.
     */
    /** Active admins open /admin; active client users of an existing client open only the client area. */
    public function canAccessPanel(Panel $panel): bool
    {
        // A turned off client login still opens while an admin looks through it (ClientAreaAccess::logInAs())
        if (! $this->active && ! ($panel->getId() === 'client' && session(ClientAreaAccess::VIEWING_AS) === $this->id)) {
            return false;
        }

        return $panel->getId() === 'client'
            ? $this->client !== null // a client user, or an admin linked to a client
            : $this->role->canAccessAdminPanel();
    }

    /**
     * The client area asks clients who signed up themselves to confirm their email address first (ClientSignup).
     * Admins are trusted (their address was entered by an admin), and logins made by an admin or through Google
     * are marked confirmed when they are made.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->role !== UserRole::Client || $this->email_verified_at !== null;
    }

    /** The Email Address Verification email, sent at once in the client's language (there is no queue worker). */
    public function sendEmailVerificationNotification(): void
    {
        app(ClientSignup::class)->sendVerification($this);
    }

    /** @return BelongsTo<Client, $this> the client a client user belongs to */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** The first admin is always a super admin (installer, `make:filament-user`), so there is one from the start. */
    /** @return HasMany<PushSubscription, $this> the devices with push notifications on (App\Push\WebPush) */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->role === UserRole::Admin && ! static::query()->admins()->where('super_admin', true)->exists()) {
                $user->super_admin = true;
            }
        });
    }

    /** Super admins open everything; other admins only the areas ticked on their user (Settings → Admin users). */
    public function canUseArea(?AdminArea $area): bool
    {
        if ($this->super_admin) {
            return true;
        }

        return $area !== null && in_array($area->value, $this->admin_areas ?? [], true);
    }

    /** @param  Builder<self>  $query */
    public function scopeAdmins(Builder $query): void
    {
        $query->where('role', UserRole::Admin);
    }

    /** Notifications (the password reset email) go out in the admin's panel language. */
    /** Panel language; a client user without one of their own gets their client's email language. */
    public function preferredLocale(): string
    {
        return Locales::normalize($this->locale ?? ($this->role === UserRole::Client ? $this->client?->locale : null));
    }

    public function hasTwoFactor(): bool
    {
        return filled($this->getAppAuthenticationSecret());
    }

    /** Admins with history (activity log entries) are deactivated, not deleted. */
    public function canBeDeleted(): bool
    {
        return ! ActivityLog::where('user_id', $this->id)->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'super_admin' => 'boolean',
            'admin_areas' => 'array',
            'active' => 'boolean',
            'system_emails' => 'boolean',
            'panel_notifications' => 'boolean',
            'dashboard_layout' => 'array',
            'last_login_at' => 'datetime',
            'two_factor_reminder_dismissed_at' => 'datetime',
        ];
    }
}

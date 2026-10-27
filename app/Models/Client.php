<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\UserRole;
use App\Support\Currencies;
use App\Support\Locales;
use App\Support\Region;
use Database\Factories\ClientFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Contact details double as the registrant contact for domain registrations,
 * so the address is kept structured (address1/2, city, state, postcode, ISO country).
 *
 * @property-read string $name
 */
#[Fillable([
    'first_name', 'last_name', 'company', 'email', 'locale', 'currency', 'phone',
    'address1', 'address2', 'city', 'state', 'postcode', 'country',
    'vat_number', 'profession', 'tax_office', 'tax_exempt', 'custom_fields',
    'status', 'default_payment_method', 'renewal_reminders', 'overdue_notices', 'informational_emails', 'notes',
])]
class Client extends Model implements HasLocalePreference
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'renewal_reminders' => true,
        'overdue_notices' => true,
        'informational_emails' => true,
        'tax_exempt' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'renewal_reminders' => 'boolean',
            'overdue_notices' => 'boolean',
            'informational_emails' => 'boolean',
            'tax_exempt' => 'boolean',
            'custom_fields' => 'array', // Settings → Client fields: {key: value} (App\Support\ClientFields)
            'credit_cents' => 'integer', // changed only through App\Credit\ClientCredit
            'terms_accepted_at' => 'immutable_datetime', // signed up accepting the terms of service (TermsOfService)
        ];
    }

    /** The language of the emails sent to this client: its own, else the default one. */
    public function preferredLocale(): string
    {
        return Locales::normalize($this->locale);
    }

    /** The currency this client is billed in (Settings → Currencies): its own, else the base one. */
    public function currencyCode(): string
    {
        return Currencies::forClient($this);
    }

    /** Whether it has services, payment notices or payments, so its currency stays as it is. */
    public function hasBilling(): bool
    {
        return $this->services()->exists() || Invoice::query()->where('client_id', $this->id)->exists()
            || $this->creditTransactions()->exists();
    }

    /** @return HasMany<CreditTransaction, $this> the credit ledger, newest last */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->currency = $client->currency ? strtoupper($client->currency) : Currencies::base();

            // Settings → General → Country when none was given (the column's own default otherwise)
            if (blank($client->country)) {
                unset($client->country);
                if ($country = Region::country()) {
                    $client->country = $country;
                }
            }
        });

        // The client-area login follows the client's name and email (unless another user already has that email).
        static::saved(function (Client $client) {
            $user = $client->wasChanged(['first_name', 'last_name', 'email']) ? $client->user : null;

            // An admin linked to the client keeps their own name and login email.
            if ($user && $user->role === UserRole::Client && ! User::query()->where('email', $client->email)->whereKeyNot($user->id)->exists()) {
                $user->update(['name' => $client->name, 'email' => $client->email]);
            }
        });
    }

    /** @return HasOne<User, $this> the client's login to the client area, if invited: a client user, or an admin linked to it */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Payment, $this> the payments the client made (a service moved to another client keeps them here) */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<ClientLogin, $this> client area sign-ins and failed tries (App\ClientSessions\ClientSessions) */
    public function clientLogins(): HasMany
    {
        return $this->hasMany(ClientLogin::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<ClientChangeRequest, $this> details the client asked to change from the client area */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(ClientChangeRequest::class);
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** "First Last". Read-only; sort and search on first_name / last_name. */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    public function displayName(): string
    {
        return $this->company ? "{$this->name} ({$this->company})" : $this->name;
    }

    /** Whether the client wants this kind of reminder ("before:N" or "overdue:N"). */
    public function wantsReminder(string $kind): bool
    {
        return str_starts_with($kind, 'overdue:') ? $this->overdue_notices : $this->renewal_reminders;
    }
}

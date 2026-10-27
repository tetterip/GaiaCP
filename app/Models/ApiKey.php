<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A key for the REST API (/api/v1, Settings → API keys, super admins). Only the SHA-256 of the key is kept: the key
 * is shown once, when it is made. Each key may do what its permissions say ("clients.read", "payments.create"…), only
 * from its allowed IPs (none = from anywhere), until it is revoked. The key of the call being answered is current():
 * the activity log notes it for what the call did.
 */
#[Fillable(['name', 'token_hash', 'token_prefix', 'permissions', 'allowed_ips', 'user_id', 'last_used_at', 'last_used_ip', 'revoked_at'])]
#[Hidden(['token_hash'])]
class ApiKey extends Model
{
    /** What a key can be given access to. */
    public const AREAS = ['clients', 'services', 'orders', 'invoices', 'payments', 'products', 'leads', 'quotes'];

    /** What it may do there. */
    public const ACTIONS = ['read', 'create', 'update', 'delete'];

    /**
     * The actions each area has: services and payment notices are made by orders and the daily run, a service ends
     * by Terminate ("delete"), a payment is undone by Reverse ("delete").
     */
    public const PERMISSIONS = [
        'clients' => ['read', 'create', 'update', 'delete'],
        'services' => ['read', 'update', 'delete'],
        'orders' => ['read', 'create', 'update', 'delete'],
        'invoices' => ['read', 'update', 'delete'],
        'payments' => ['read', 'create', 'delete'],
        'products' => ['read', 'create', 'update', 'delete'],
        'leads' => ['read', 'create', 'update', 'delete'],
        'quotes' => ['read', 'create', 'update', 'delete'],
    ];

    /** Every key starts like this, so a leaked one is easy to recognise. */
    public const PREFIX = 'gcp_';

    private static ?self $current = null;

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'allowed_ips' => 'array',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Makes a key. Returns it with the key itself, which is not kept and can't be read back.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: self, 1: string}
     */
    public static function generate(array $attributes): array
    {
        $token = self::PREFIX.Str::random(40);

        $key = self::query()->create($attributes + [
            'token_hash' => self::hash($token),
            'token_prefix' => substr($token, 0, 12),
            'user_id' => auth()->id(),
        ]);

        return [$key, $token];
    }

    /** The active key a call's bearer token belongs to, if any. */
    public static function findByToken(?string $token): ?self
    {
        if (blank($token) || ! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        return self::query()->active()->where('token_hash', self::hash($token))->first();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The key of the API call being answered, null outside one. */
    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(?self $key): void
    {
        self::$current = $key;
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function revoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Whether it may do $action ("read", "create", "update", "delete") on $area ("clients"…). */
    public function can(string $area, string $action): bool
    {
        return in_array("{$area}.{$action}", $this->permissions ?? [], true);
    }

    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->allowed_ips ?? [];

        return $allowed === [] || ($ip !== null && IpUtils::checkIp($ip, $allowed));
    }

    /**
     * The permissions as the settings form shows them, one list per area.
     *
     * @return array<string, list<string>>
     */
    public function permissionsByArea(): array
    {
        $byArea = array_fill_keys(self::AREAS, []);

        foreach ($this->permissions ?? [] as $permission) {
            [$area, $action] = array_pad(explode('.', $permission, 2), 2, null);
            if (isset($byArea[$area]) && in_array($action, self::PERMISSIONS[$area], true)) {
                $byArea[$area][] = $action;
            }
        }

        return $byArea;
    }

    /**
     * The form's lists back as permission names, only the known ones.
     *
     * @param  array<string, mixed>  $byArea
     * @return list<string>
     */
    public static function permissionsFrom(array $byArea): array
    {
        $permissions = [];

        foreach (self::PERMISSIONS as $area => $actions) {
            foreach ($actions as $action) {
                if (in_array($action, (array) ($byArea[$area] ?? []), true)) {
                    $permissions[] = "{$area}.{$action}";
                }
            }
        }

        return $permissions;
    }

    /** @return array<string, string> area => its name, in the current language */
    public static function areaOptions(): array
    {
        return [
            'clients' => __('Clients'),
            'services' => __('Services'),
            'orders' => __('Orders'),
            'invoices' => __('Payment notices'),
            'payments' => __('Payments'),
            'products' => __('Products'),
            'leads' => __('Leads'),
            'quotes' => __('Quotes'),
        ];
    }

    /** @return array<string, string> action => its name, in the current language */
    public static function actionOptions(): array
    {
        return [
            'read' => __('Read'),
            'create' => __('Create'),
            'update' => __('Update'),
            'delete' => __('Delete'),
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

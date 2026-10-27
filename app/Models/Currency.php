<?php

namespace App\Models;

use App\Enums\CurrencyFormat;
use App\Support\Currencies;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A currency clients can be billed in (Settings → Currencies). `rate` is how many of it one unit of the base
 * currency (gaiacp.currency) buys; the base currency's is always 1.
 */
#[Fillable(['code', 'prefix', 'suffix', 'format', 'rate'])]
class Currency extends Model
{
    protected function casts(): array
    {
        return [
            'format' => CurrencyFormat::class,
            'rate' => 'decimal:5',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Currencies::flush());
        static::deleted(fn () => Currencies::flush());
    }

    protected function code(): Attribute
    {
        return Attribute::set(fn (string $value) => strtoupper(trim($value)));
    }

    public function isBase(): bool
    {
        return $this->code === Currencies::base();
    }

    /** Whether any client, service, payment notice or payment uses it, so it cannot be deleted. */
    public function inUse(): bool
    {
        return Client::query()->where('currency', $this->code)->exists()
            || Service::query()->where('currency', $this->code)->exists()
            || Invoice::query()->where('currency', $this->code)->exists()
            || Payment::query()->where('currency', $this->code)->exists();
    }
}

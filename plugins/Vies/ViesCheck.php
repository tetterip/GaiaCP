<?php

namespace Plugins\Vies;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One VIES answer for a VAT number (Vies::check()). Kept by number rather than by client, so a check made on the New
 * client form counts once the client is saved, and a changed VAT number needs a check of its own.
 *
 * @property CarbonImmutable $checked_at
 */
#[Fillable(['country_code', 'vat_number', 'valid', 'name', 'address', 'request_identifier', 'user_id', 'checked_at'])]
class ViesCheck extends Model
{
    protected function casts(): array
    {
        return [
            'valid' => 'boolean',
            'checked_at' => 'immutable_datetime',
        ];
    }
}

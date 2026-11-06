<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One value from the Settings page; read through App\Support\Settings, not directly. */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use StoresTimesInUtc;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}

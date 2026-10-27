<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** An edited email (subject, body, on/off) keyed by App\Enums\MailTemplate; read through App\Support\MailTemplates. */
#[Fillable(['key', 'subject', 'body', 'enabled'])]
class EmailTemplate extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_id', 'due_date', 'kind', 'sent_to', 'sent_at'])]
class Reminder extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}

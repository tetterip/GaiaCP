<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A support department (Settings → Ticket departments): where a ticket goes; clients pick among the visible ones. */
#[Fillable(['name', 'email', 'client_visible', 'sort'])]
class TicketDepartment extends Model
{
    protected $attributes = [
        'client_visible' => true,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'client_visible' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'department_id');
    }

    /** @param Builder<TicketDepartment> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name');
    }

    /** @param Builder<TicketDepartment> $query */
    public function scopeClientVisible(Builder $query): void
    {
        $query->where('client_visible', true);
    }
}

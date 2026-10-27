<?php

namespace Plugins\Blacklists;

use App\Models\Server;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A server's IP on a DNS blacklist right now (deleted once the list no longer has it). */
#[Fillable(['server_id', 'ip', 'zone', 'answer', 'listed_at', 'checked_at'])]
class BlacklistListing extends Model
{
    protected function casts(): array
    {
        return [
            'listed_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}

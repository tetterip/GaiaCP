<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keys are made in the panel, so one without an admin lost theirs when the admin was deleted (nullOnDelete) and kept
 * working. Deleting an admin now revokes their keys (User::booted()); this revokes the ones left from before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('api_keys')->whereNull('user_id')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function down(): void {}
};

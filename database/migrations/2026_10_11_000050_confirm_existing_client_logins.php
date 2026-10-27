<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The client area now asks clients who sign up themselves to confirm their email address (users.email_verified_at).
 * Logins made before then came from an admin, so they count as confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to undo: the dates are harmless.
    }
};

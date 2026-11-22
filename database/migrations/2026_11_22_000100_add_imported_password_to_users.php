<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The password hash of a login imported from another system (the WHMCS import), in that system's format: checked by
 * App\Auth\ImportedPassword on a login, then replaced by GaiaCP's own hash and emptied (App\Auth\UserProvider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('imported_password')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('imported_password');
        });
    }
};

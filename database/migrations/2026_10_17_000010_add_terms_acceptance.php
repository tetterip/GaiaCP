<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a client accepted the terms of service (Settings → Client area → Terms of service) and which version: on the
 * order placed in the client area, and on the client for a sign up. Null = not asked (an admin's order, or the terms
 * were off).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('terms_accepted_at')->nullable();
                $table->string('terms_version', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['terms_accepted_at', 'terms_version']);
            });
        }
    }
};

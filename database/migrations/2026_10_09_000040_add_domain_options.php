<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Late renewal fees per TLD (grace and redemption periods, as in WHMCS) and the
 * fee lines they add to a payment notice. A domain's "auto renew" is the inverse of
 * its service's auto_terminate, so it needs no column of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrar_tlds', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_days')->nullable(); // days after expiry before the redemption period
            $table->unsignedInteger('grace_fee_cents')->nullable();
            $table->unsignedInteger('redemption_fee_cents')->nullable();
        });

        // null = the service's cycle; "grace" / "redemption" = a late renewal fee for that cycle.
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('fee')->nullable()->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('fee');
        });

        Schema::table('registrar_tlds', function (Blueprint $table) {
            $table->dropColumn(['grace_days', 'grace_fee_cents', 'redemption_fee_cents']);
        });
    }
};

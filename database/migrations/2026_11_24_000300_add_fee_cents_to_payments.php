<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // What the payment provider kept of it (Stripe, PayPal), in the payment's currency; null = none or not known
            $table->unsignedInteger('fee_cents')->nullable()->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('fee_cents');
        });
    }
};

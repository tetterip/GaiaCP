<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A client's "I've paid" on a notice (App\Actions\PaymentClaims): when and how they say they paid, until an admin checks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('payment_claimed_at')->nullable()->after('emailed_at');
            $table->string('payment_claim_method', 50)->nullable()->after('payment_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_claimed_at', 'payment_claim_method']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a payment gateway reported after the payment (App\Payments\ProviderReports): refunds made at the
        // provider and chargebacks. One row per gateway payment (method + reference, as on `payments`).
        Schema::create('provider_payment_states', function (Blueprint $table) {
            $table->id();
            $table->string('method', 50);
            $table->string('reference', 191);
            $table->char('currency', 3);
            // The total the provider says it has given back so far (it only grows)
            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->string('dispute_id', 191)->nullable();
            // open, won or lost
            $table->string('dispute_status', 20)->nullable();
            $table->unsignedBigInteger('dispute_cents')->nullable();
            $table->string('dispute_reason', 191)->nullable();
            $table->timestamps();
            $table->unique(['method', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_payment_states');
    }
};

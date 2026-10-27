<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Stripe Checkout page opened for a payment notice (Plugins\Stripe): what it asked for and what came of it, so
     * a payment is recorded once however often Stripe reports it. status: open, processing, paid, expired, failed,
     * unmatched (paid at Stripe but not recorded here; `error` says why).
     */
    public function up(): void
    {
        Schema::create('stripe_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->unique();
            $table->boolean('livemode')->default(false);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 20)->default('open')->index();
            $table->string('payment_intent')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_checkouts');
    }
};

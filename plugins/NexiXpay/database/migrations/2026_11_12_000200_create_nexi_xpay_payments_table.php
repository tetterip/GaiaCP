<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Nexi XPay card payment started for a payment notice (Plugins\NexiXpay): what it asked for and what Nexi
     * said of it, so a payment is recorded once however often Nexi reports it. status: open, processing, pending (Nexi
     * has not settled the outcome yet), paid, cancelled (on Nexi's page), failed (refused), expired (never finished),
     * unmatched (paid at Nexi but not recorded here; `error` says why).
     */
    public function up(): void
    {
        Schema::create('nexi_xpay_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->index()->constrained()->cascadeOnDelete();
            $table->string('order_id', 18)->unique();
            $table->boolean('live')->default(false);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->text('security_token')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->string('operation_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexi_xpay_payments');
    }
};

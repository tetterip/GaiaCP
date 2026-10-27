<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->date('paid_on');
            $table->string('method'); // App\Payments\PaymentMethods
            $table->string('reference')->nullable();
            // The period this payment covered, for auditing and undo.
            $table->date('due_date_before');
            $table->date('due_date_after');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

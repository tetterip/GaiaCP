<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('type'); // App\Enums\ServiceType
            $table->foreignId('server_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('label');
            $table->string('domain')->nullable();
            $table->string('username')->nullable();
            $table->string('package')->nullable();
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('billing_cycle'); // App\Enums\BillingCycle
            $table->date('start_date');
            $table->date('next_due_date');
            $table->string('status')->default('pending'); // App\Enums\ServiceStatus
            $table->string('suspension_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};

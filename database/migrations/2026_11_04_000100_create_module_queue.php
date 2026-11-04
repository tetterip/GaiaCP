<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services → Module queue: a panel or registrar action that failed (set up, suspend, unsuspend, terminate, domain
 * renewal), kept until it is retried with success, marked resolved, or no longer needed (App\Actions\ModuleQueue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('module')->nullable();
            $table->string('reason')->nullable();
            $table->text('error');
            $table->unsignedInteger('attempts')->default(1);
            $table->unsignedInteger('auto_attempts')->default(0);
            $table->timestamp('last_attempt_at');
            $table->timestamp('gave_up_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_id', 'action', 'resolved_at']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_queue');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per reminder actually sent; the unique key is what makes the
        // daily job safe to re-run.
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->string('kind'); // e.g. "before:7", "overdue:3"
            $table->string('sent_to');
            $table->timestamp('sent_at');

            $table->unique(['service_id', 'due_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The usage warnings sent (gaiacp:check-usage): one per hosting account, kind (disk / bandwidth) and month. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('period', 7); // Y-m
            $table->unsignedSmallInteger('percent');
            $table->timestamp('created_at')->nullable();
            $table->unique(['service_id', 'kind', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_warnings');
    }
};

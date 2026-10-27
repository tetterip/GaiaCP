<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The last DNS and HTTPS check of an active hosting account's domain (one row per service). */
    public function up(): void
    {
        Schema::create('site_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('dns_status', 20);
            $table->json('addresses')->nullable();
            $table->string('ssl_status', 20);
            $table->timestamp('ssl_valid_to')->nullable();
            $table->string('ssl_issuer')->nullable();
            $table->string('ssl_error')->nullable();
            // The problems already told in the bell, so each one is told once
            $table->json('alerted')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_checks');
    }
};

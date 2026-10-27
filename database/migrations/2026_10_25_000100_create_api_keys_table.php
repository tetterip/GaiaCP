<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** REST API keys (Settings → API keys), and which key did what in the activity log. */
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique(); // SHA-256 of the key: the key itself is shown once and never kept
            $table->string('token_prefix', 20); // its first characters, to tell keys apart
            $table->json('permissions'); // ["clients.read", "payments.create", …] (ApiKey::AREAS × ApiKey::ACTIONS)
            $table->json('allowed_ips')->nullable(); // IPs or CIDR ranges; null = from anywhere
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who made it
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('api_key_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('api_key_id');
        });

        Schema::dropIfExists('api_keys');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Addresses kept out of the login, sign up and forgot password pages (App\Support\LoginSecurity)
        Schema::create('ip_bans', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('reason')->nullable(); // null = too many failed logins (attempts)
            $table->unsignedSmallInteger('attempts')->nullable();
            $table->timestamp('expires_at')->nullable()->index(); // null = until lifted
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // the admin who added it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_bans');
    }
};

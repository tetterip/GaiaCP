<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrars', function (Blueprint $table) {
            // Off only while the registrar's API certificate is broken (e.g. expired).
            $table->boolean('verify_tls')->default(true)->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('registrars', fn (Blueprint $table) => $table->dropColumn('verify_tls'));
    }
};

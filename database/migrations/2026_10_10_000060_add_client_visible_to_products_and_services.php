<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Show in client area": whether clients see a service (and can order a product) once there is a client area.
 * On for everything except Maintenance, which only the admin offers. The service keeps its own copy, like auto_setup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('client_visible')->default(true)->after('retired');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->boolean('client_visible')->default(true)->after('auto_setup');
        });

        DB::table('products')->where('type', 'maintenance')->update(['client_visible' => false]);
        DB::table('services')->where('type', 'maintenance')->update(['client_visible' => false]);
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('client_visible'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('client_visible'));
    }
};

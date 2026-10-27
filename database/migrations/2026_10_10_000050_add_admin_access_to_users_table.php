<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('super_admin')->default(false)->after('role'); // everything, including Admin users
            $table->json('admin_areas')->nullable()->after('super_admin'); // App\Enums\AdminArea values, for the others
        });

        // Everyone who could do everything before keeps doing so.
        DB::table('users')->update(['super_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['super_admin', 'admin_areas']);
        });
    }
};

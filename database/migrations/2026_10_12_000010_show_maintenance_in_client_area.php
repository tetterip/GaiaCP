<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Maintenance was hidden from clients by default; clients now see it read-only (it is still never orderable).
        DB::table('products')->where('type', 'maintenance')->update(['client_visible' => true]);
        DB::table('services')->where('type', 'maintenance')->update(['client_visible' => true]);
    }

    public function down(): void
    {
        DB::table('products')->where('type', 'maintenance')->update(['client_visible' => false]);
        DB::table('services')->where('type', 'maintenance')->update(['client_visible' => false]);
    }
};

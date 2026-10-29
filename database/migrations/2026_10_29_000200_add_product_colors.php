<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A product's own colour on the order pages (one of ProductTag::COLORS; null = the client area's accent colour).
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('color', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('color'));
    }
};

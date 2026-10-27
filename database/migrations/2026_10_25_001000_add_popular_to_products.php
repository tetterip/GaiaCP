<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A product marked Popular carries a badge on its order card, the public home page and the compare table.
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('popular')->default(false)->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('popular');
        });
    }
};

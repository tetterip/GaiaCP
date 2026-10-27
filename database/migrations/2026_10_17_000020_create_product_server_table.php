<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A hosting product can live on several servers with the same package; a new order goes to the emptiest.
        Schema::create('product_server', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unique(['product_id', 'server_id']);
        });

        DB::table('products')->whereNotNull('server_id')->orderBy('id')->each(function (object $product) {
            DB::table('product_server')->insert(['product_id' => $product->id, 'server_id' => $product->server_id]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->after('billing_cycle')->constrained()->nullOnDelete();
        });

        DB::table('product_server')->orderBy('id')->each(function (object $row) {
            DB::table('products')->where('id', $row->product_id)->whereNull('server_id')->update(['server_id' => $row->server_id]);
        });

        Schema::dropIfExists('product_server');
    }
};

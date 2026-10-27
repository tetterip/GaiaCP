<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Product groups (Settings → Product groups, App\Models\ProductGroup): the order page, the public home page and the
    // public side menu show products by group, each product with an icon and its values for the group's features (the
    // rows of the compare table). An install that has products gets one group per type that clients can order (Hosting,
    // SSL certificates, Other services), so its order page shows them as before.
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->json('names');
            $table->json('descriptions')->nullable();
            $table->string('icon', 50)->nullable();
            $table->json('features')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_group_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->string('icon', 50)->nullable()->after('description');
            $table->json('features')->nullable()->after('icon');
        });

        $groups = [
            'hosting' => ['slug' => 'hosting', 'name' => 'Hosting', 'icon' => 'server'],
            'ssl' => ['slug' => 'ssl-certificates', 'name' => 'SSL certificates', 'icon' => 'lock'],
            'other' => ['slug' => 'other-services', 'name' => 'Other services', 'icon' => 'squares'],
        ];
        $sort = 0;

        foreach ($groups as $type => $group) {
            $products = DB::table('products')->where('type', $type)->where('addon', false);
            if (! $products->exists()) {
                continue;
            }

            $id = DB::table('product_groups')->insertGetId([
                'slug' => $group['slug'],
                'names' => json_encode(['en' => $group['name'], 'el' => __($group['name'], [], 'el')], JSON_UNESCAPED_UNICODE),
                'icon' => $group['icon'],
                'sort' => ++$sort,
                'hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $products->update(['product_group_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_group_id');
            $table->dropColumn(['icon', 'features']);
        });

        Schema::dropIfExists('product_groups');
    }
};

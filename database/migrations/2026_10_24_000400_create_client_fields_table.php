<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom client fields (Settings → Client fields): the admin's own fields on the client form, sign up and the client's
 * profile, each with a label per language. The values are on the client, `clients.custom_fields` ({key: value}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_fields', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->json('labels');
            $table->string('type', 20)->default('text');
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->string('client_access', 20)->default('editable');
            $table->boolean('on_notice')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('profession');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('custom_fields'));
        Schema::dropIfExists('client_fields');
    }
};

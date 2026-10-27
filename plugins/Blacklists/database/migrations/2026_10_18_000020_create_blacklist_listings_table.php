<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A server's IP on a DNS blacklist right now: a row while it is listed, deleted once it is not. */
    public function up(): void
    {
        Schema::create('blacklist_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('zone');
            $table->string('answer')->nullable();
            $table->timestamp('listed_at');
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->unique(['server_id', 'ip', 'zone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_listings');
    }
};

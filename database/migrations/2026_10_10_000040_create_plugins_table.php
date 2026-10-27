<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On/off switch per plugin (App\Plugins\PluginManager); no row = on, as the bundled plugins start.
        Schema::create('plugins', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->string('version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};

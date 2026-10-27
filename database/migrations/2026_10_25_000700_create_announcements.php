<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The public area, part 4: announcements (Support → Announcements, App\Models\Announcement), each for everyone or
    // only for clients, and the servers' status page (Client\Pages\ServerStatus), where a server shows under its
    // public name (else its name; never its hostname or IP address) unless it is left off.
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->json('texts');
            $table->timestamp('published_at')->nullable()->index();
            $table->boolean('clients_only')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('public_name', 100)->nullable()->after('name');
            $table->boolean('on_status_page')->default(true)->after('public_name');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['public_name', 'on_status_page']);
        });

        Schema::dropIfExists('announcements');
    }
};

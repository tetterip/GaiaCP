<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The devices where an admin turned on push notifications (App\Push\WebPush): one row per browser subscription. */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique(); // sha256 of the endpoint: too long for a unique index of its own
            $table->text('endpoint');
            $table->text('public_key'); // encrypted: the browser's p256dh key
            $table->text('auth_token'); // encrypted: the browser's auth secret
            $table->string('user_agent', 500)->nullable(); // to tell the devices apart (ClientSessions::device())
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Messages for a chat plugin (Slack, Discord) waiting to be sent, or sent, by gaiacp:send-chat-messages. */
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 40); // the plugin's id
            $table->string('event', 60);
            $table->text('body'); // the JSON posted to the channel's webhook
            $table->string('status', 20)->default('pending'); // pending, sent, failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};

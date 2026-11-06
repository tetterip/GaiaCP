<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two date filters MySQL read the whole table for (EXPLAIN on staging, 2026-11-06): payments by the day they were
 * paid (income report, dashboard, API paid_from / paid_to) and pending orders by age (gaiacp:cancel-unpaid-orders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->index('paid_on'));
        Schema::table('orders', fn (Blueprint $table) => $table->index(['status', 'created_at']));
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(['paid_on']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(['status', 'created_at']));
    }
};

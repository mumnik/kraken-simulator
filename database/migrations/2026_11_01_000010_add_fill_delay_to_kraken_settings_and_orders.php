<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A market (or market-crossing limit) order on the real Kraken doesn't
 * report its fill back instantly the way this simulator always has until
 * now — a real caller learns it only by polling QueryOrders, same as any
 * other order. fill_delay_seconds (0 = today's instant-fill behavior,
 * unchanged) lets a test or demo exercise that polling path for real,
 * instead of it only ever succeeding on the very first check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraken_settings', function (Blueprint $table) {
            $table->unsignedInteger('fill_delay_seconds')->default(0)->after('random_fail_probability');
        });

        Schema::table('kraken_orders', function (Blueprint $table) {
            // Set only for an order whose fill is deliberately deferred —
            // null for everything else (a resting limit order away from
            // market, an already-rejected/canceled order, or any order
            // placed while fill_delay_seconds was 0).
            $table->timestampTz('closes_at')->nullable()->after('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('kraken_settings', function (Blueprint $table) {
            $table->dropColumn('fill_delay_seconds');
        });
        Schema::table('kraken_orders', function (Blueprint $table) {
            $table->dropColumn('closes_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * live (the default) — Pricer::read() refreshes mid_price from the real
 * Kraken's own public Ticker when it's older than
 * config('kraken.live_refresh_seconds'), so the "market" genuinely tracks
 * the real one rather than sitting on a fixed demo number forever. manual
 * — POST /internal/prices set this pair and it stays exactly there until
 * POST /internal/prices/live asks for it back; a live refresh never
 * touches a manual pair, so a scenario deliberately engineered for a test
 * (a specific price, a specific R1-vs-R2 drift) can't be silently
 * overwritten by the next read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraken_prices', function (Blueprint $table) {
            // sqlite can't ADD CONSTRAINT via ALTER TABLE — 'live'/'manual'
            // is validated in KrakenPrice/InternalController instead, same
            // as every other enum-shaped string column in this schema.
            $table->string('source', 8)->default('live')->after('mid_price');
            $table->timestampTz('live_synced_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('kraken_prices', function (Blueprint $table) {
            $table->dropColumn(['source', 'live_synced_at']);
        });
    }
};

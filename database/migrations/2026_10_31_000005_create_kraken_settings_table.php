<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton row (id=1, enforced in the model, not the schema — sqlite has
 * no partial-unique-index support worth reaching for here) — the knobs
 * POST /internal/settings adjusts at runtime: how much a Ticker read
 * wobbles off kraken_prices.mid_price, and how often an otherwise-valid
 * AddOrder gets randomly rejected anyway (real exchanges do this: rate
 * limits, momentary liquidity gaps, risk-engine hiccups — not just "you
 * didn't have the money").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kraken_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('volatility_pct', 6, 4)->default(0.15); // ± this % per Ticker read
            $table->decimal('random_fail_probability', 6, 4)->default(0); // 0..1, chance a VALID order is rejected anyway
            $table->timestamps();
        });

        DB::table('kraken_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kraken_settings');
    }
};

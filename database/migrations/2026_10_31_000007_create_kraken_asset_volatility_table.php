<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces kraken_settings.volatility_pct (one global wobble for every
 * pair) — real assets don't all move the same amount; USDT isn't BTC. One
 * row per asset; a pair's own wobble combines both sides (see
 * AssetVolatility::forPair()'s own docblock for why quadrature, not a
 * sum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kraken_asset_volatility', function (Blueprint $table) {
            $table->string('asset', 16)->primary();
            $table->decimal('volatility_pct', 6, 4);
            $table->timestamps();
        });

        Schema::table('kraken_settings', function (Blueprint $table) {
            $table->dropColumn('volatility_pct');
        });
    }

    public function down(): void
    {
        Schema::table('kraken_settings', function (Blueprint $table) {
            $table->decimal('volatility_pct', 6, 4)->default(0.15);
        });
        Schema::dropIfExists('kraken_asset_volatility');
    }
};

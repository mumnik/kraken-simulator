<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-credential, per-asset balance — what AddOrder checks before filling
 * an order (spot: enough of the asset being sold; margin: enough free
 * margin, see kraken_credentials' own TradeBalance derivation). Our own
 * asset codes (BTC, ETH, EUR, USD, USDT) — Kraken's XBT/Z-prefix quirks are
 * a response-shaping concern at the HTTP boundary, not stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kraken_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kraken_api_credential_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 16);
            // Decimal stored as a string-backed column (not float) —
            // avoids float precision loss on crypto-scale amounts; cast to
            // a decimal string everywhere this is read.
            $table->decimal('amount', 24, 10)->default(0);
            $table->timestamps();

            $table->unique(['kraken_api_credential_id', 'asset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kraken_balances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per base/quote pair — the mid price KrakenPricer jitters around
 * on every /0/public/Ticker read (see that class for the volatility model)
 * and AddOrder fills market orders at. Admin-settable via
 * POST /internal/prices, e.g. to engineer a specific R1-vs-R2 drift for a
 * test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kraken_prices', function (Blueprint $table) {
            $table->id();
            $table->string('base', 16);
            $table->string('quote', 16);
            $table->decimal('mid_price', 24, 10);
            $table->timestamps();

            $table->unique(['base', 'quote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kraken_prices');
    }
};

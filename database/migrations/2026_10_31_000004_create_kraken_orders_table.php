<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every AddOrder attempt — including rejected ones (status=rejected,
 * reject_reason set), so OpenOrders/QueryOrders and the /internal/orders
 * admin view can show the full history, not just fills. A market order
 * fills immediately at the current jittered price (status=closed); a
 * limit order away from the market sits open (status=open) until
 * CancelOrder or (not simulated yet) a future price walk through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kraken_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kraken_api_credential_id')->constrained()->cascadeOnDelete();
            $table->string('txid', 24)->unique();
            $table->string('pair', 16); // e.g. "BTC/EUR", our own codes
            $table->string('type', 4); // buy | sell
            $table->string('ordertype', 16); // market | limit
            $table->decimal('volume', 24, 10);
            $table->decimal('price', 24, 10)->nullable(); // limit price, or the fill price once closed
            $table->decimal('leverage', 6, 2)->nullable();
            $table->string('status', 16); // open | closed | canceled | rejected
            $table->string('reject_reason', 64)->nullable(); // Kraken error code, e.g. EOrder:Insufficient funds
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kraken_orders');
    }
};

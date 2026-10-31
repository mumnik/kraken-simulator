<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not part of Kraken's real protocol at all — real Kraken has no webhooks,
 * only REST polling and a WebSocket subscribe feed. This is this
 * simulator's own addition, for code (today: nothing; eventually, a real
 * hedging feature in mumnik/ledger) that wants to react to an order
 * filling/failing without polling QueryOrders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kraken_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            // null = fires for every credential's events, not just one —
            // the common case for a single test listener.
            $table->foreignId('kraken_api_credential_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('secret', 128); // signs the outgoing payload, see WebhookDispatcher
            $table->json('events'); // e.g. ["order.filled", "order.rejected"]
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kraken_webhook_endpoints');
    }
};

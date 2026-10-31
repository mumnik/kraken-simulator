<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every delivery attempt, success or failure — GET /internal/webhooks/
 * deliveries is how a test or a demo answers "did my webhook actually
 * fire, and what did the endpoint say back" without needing its own
 * logging on the receiving end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kraken_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kraken_webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kraken_webhook_deliveries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per simulated Kraken account. `secret` is stored as Kraken itself
 * hands it out — base64 — so request-signature verification (api-key
 * middleware) can base64_decode() it exactly the way a real client's SDK
 * would before HMAC'ing. `last_nonce` enforces Kraken's own anti-replay
 * rule: every private call's nonce must be strictly greater than the
 * previous one accepted for this key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kraken_api_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('api_key', 64)->unique();
            $table->string('api_secret', 128);
            $table->unsignedBigInteger('last_nonce')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kraken_api_credentials');
    }
};

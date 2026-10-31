<?php

namespace App\Kraken;

use App\Models\KrakenPrice;
use App\Models\KrakenSetting;
use InvalidArgumentException;

/**
 * Reads/sets the mid price for a base/quote pair, and jitters a READ (never
 * the stored mid_price itself) by ± KrakenSetting::volatility_pct — a real
 * exchange's last-trade price wobbles tick to tick; a Ticker read here
 * should too, rather than ever answering the exact same number twice in a
 * row. The wobble is never persisted, so repeated reads wander around the
 * stored mid, never drift away from it — set() (POST /internal/prices) is
 * the only thing that actually moves the center.
 *
 * AddOrder's market-order fills use the same jittered read, so an order's
 * actual fill price and a Ticker call made around the same time can differ
 * slightly too, same as on a real exchange.
 */
class Pricer
{
    /** Jittered mid price, as a float. Throws if the pair isn't seeded. */
    public function read(string $base, string $quote): float
    {
        $mid = (float) $this->row($base, $quote)->mid_price;
        $volatility = (float) KrakenSetting::current()->volatility_pct / 100;
        $wobble = 1 + (mt_rand(-10000, 10000) / 10000) * $volatility;

        return $mid * $wobble;
    }

    public function set(string $base, string $quote, float $midPrice): void
    {
        if ($midPrice <= 0) {
            throw new InvalidArgumentException('mid_price must be positive');
        }
        KrakenPrice::updateOrCreate(
            ['base' => strtoupper($base), 'quote' => strtoupper($quote)],
            ['mid_price' => $midPrice],
        );
    }

    public function exists(string $base, string $quote): bool
    {
        return KrakenPrice::where('base', strtoupper($base))->where('quote', strtoupper($quote))->exists();
    }

    private function row(string $base, string $quote): KrakenPrice
    {
        $row = KrakenPrice::where('base', strtoupper($base))->where('quote', strtoupper($quote))->first();
        if ($row === null) {
            throw new InvalidArgumentException("No price seeded for {$base}/{$quote}");
        }

        return $row;
    }
}

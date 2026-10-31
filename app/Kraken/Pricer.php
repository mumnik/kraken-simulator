<?php

namespace App\Kraken;

use App\Models\KrakenPrice;
use InvalidArgumentException;

/**
 * Reads/sets the mid price for a base/quote pair. A 'live' pair (the
 * default — see kraken_prices.source's own migration docblock) is
 * refreshed from the real Kraken whenever it's older than
 * config('kraken.live_refresh_seconds'); a 'manual' one (set() without
 * $keepLive, or POST /internal/prices) never is, so a scenario deliberately
 * engineered for a test holds until POST /internal/prices/live asks for it
 * back.
 *
 * Every READ is jittered by ± AssetVolatility::forPair() — a real
 * exchange's last-trade price wobbles tick to tick; a Ticker read here
 * should too, rather than ever answering the exact same number twice in a
 * row. The wobble is never persisted, so repeated reads wander around the
 * live-or-manual mid, never drift away from it on their own.
 *
 * AddOrder's market-order fills use the same jittered read, so an order's
 * actual fill price and a Ticker call made around the same time can differ
 * slightly too, same as on a real exchange.
 */
class Pricer
{
    public function __construct(
        private readonly AssetVolatility $volatility,
        private readonly LiveKrakenClient $live,
    ) {
    }

    /** Jittered mid price, as a float. Throws if the pair isn't seeded. */
    public function read(string $base, string $quote): float
    {
        $row = $this->row($base, $quote);
        if ($row->isLive() && $this->isStale($row)) {
            $this->refreshFromLive($row, $base, $quote);
        }

        $mid = (float) $row->mid_price;
        $wobblePct = $this->volatility->forPair($base, $quote) / 100;
        $wobble = 1 + (mt_rand(-10000, 10000) / 10000) * $wobblePct;

        return $mid * $wobble;
    }

    /**
     * $source: 'manual' (the default — an explicit admin override, see
     * InternalController::setPrice()) or 'live' (the seeder's own initial
     * baseline — tracks the real market from the start, this call just
     * seeds where it starts before the first real fetch).
     */
    public function set(string $base, string $quote, float $midPrice, string $source = 'manual'): void
    {
        if ($midPrice <= 0) {
            throw new InvalidArgumentException('mid_price must be positive');
        }
        KrakenPrice::updateOrCreate(
            ['base' => strtoupper($base), 'quote' => strtoupper($quote)],
            ['mid_price' => $midPrice, 'source' => $source],
        );
    }

    /** Switches a pair back to tracking the real market — the next read() refreshes it. */
    public function goLive(string $base, string $quote): void
    {
        $this->row($base, $quote)->update(['source' => 'live', 'live_synced_at' => null]);
    }

    public function exists(string $base, string $quote): bool
    {
        return KrakenPrice::where('base', strtoupper($base))->where('quote', strtoupper($quote))->exists();
    }

    private function refreshFromLive(KrakenPrice $row, string $base, string $quote): void
    {
        $fresh = $this->live->fetch($base, $quote);
        // A failed fetch still touches live_synced_at — otherwise a
        // genuinely unreachable real Kraken gets hit on every single read
        // instead of backing off for the same refresh window a successful
        // fetch would.
        $row->update($fresh !== null ? ['mid_price' => $fresh, 'live_synced_at' => now()] : ['live_synced_at' => now()]);
    }

    private function isStale(KrakenPrice $row): bool
    {
        if ($row->live_synced_at === null) {
            return true;
        }

        return $row->live_synced_at->diffInSeconds(now()) >= (int) config('kraken.live_refresh_seconds', 30);
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

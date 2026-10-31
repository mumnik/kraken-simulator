<?php

namespace App\Kraken;

use App\Models\KrakenAssetVolatility;

/**
 * Per-asset, not per-pair — a real market's wobble is really a property of
 * each side (BTC moves a lot regardless of what it's quoted against; USDT
 * barely moves at all), so this scales with however many assets exist
 * rather than every pair needing its own number, and stays consistent
 * across every pair that asset appears in.
 */
class AssetVolatility
{
    // Used only for an asset nobody's configured yet (set() was never
    // called for it) — moderate, not zero, so a newly added asset doesn't
    // silently read as a dead-still price.
    private const DEFAULT_PCT = 0.5;

    public function forAsset(string $asset): float
    {
        return (float) (KrakenAssetVolatility::find(strtoupper($asset))?->volatility_pct ?? self::DEFAULT_PCT);
    }

    /**
     * Two independent sources of noise (the base asset's own wobble and
     * the quote asset's own) combine in quadrature — sqrt(a² + b²) — the
     * standard way to combine independent standard deviations. A plain
     * sum would double-count "both happen to wobble the same way at
     * once", which isn't actually guaranteed.
     */
    public function forPair(string $base, string $quote): float
    {
        $a = $this->forAsset($base);
        $b = $this->forAsset($quote);

        return sqrt($a ** 2 + $b ** 2);
    }

    public function set(string $asset, float $volatilityPct): void
    {
        KrakenAssetVolatility::updateOrCreate(['asset' => strtoupper($asset)], ['volatility_pct' => $volatilityPct]);
    }
}

<?php

namespace Database\Seeders;

use App\Kraken\AssetVolatility;
use App\Kraken\Pricer;
use App\Models\KrakenApiCredential;
use App\Models\KrakenAssetVolatility;
use App\Models\KrakenBalance;
use App\Models\KrakenPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Safe to re-run on every deploy (migrate --seed, same convention as every
 * sibling service in this ecosystem) — never regenerates the demo
 * credential if one already exists, since rotating its secret under a
 * client's feet (or resetting last_nonce, which would make its very next
 * real request look like a replay) would break whatever's already using
 * it. Prices, same idea: updateOrCreate so a re-seed never undoes a price
 * someone deliberately set via POST /internal/prices mid-demo.
 */
class DatabaseSeeder extends Seeder
{
    public function run(Pricer $pricer, AssetVolatility $volatility): void
    {
        $this->seedPrices($pricer);
        $this->seedVolatility($volatility);
        $this->seedDemoCredential();
    }

    private function seedPrices(Pricer $pricer): void
    {
        // Starting points only — seeded as 'live', so the very first read
        // of each pulls a real price from the real Kraken and these
        // numbers never actually get used unless that fetch fails (no
        // network in CI, real Kraken briefly down, etc.), in which case
        // they're what a client sees instead of an error. Pairs chosen to
        // cover this project's own backend (BTC/USDT wallets, EUR/USD
        // fiat ledgers) plus ETH for a little extra breadth. BTC/USDT and
        // ETH/USDT are real Kraken pairs too (XBTUSDT/ETHUSDT) — found
        // missing live 2026-11-01: KrakenVenue has no triangulation
        // through a third currency, only a direct pair or its exact
        // reverse, so a crypto/crypto conversion with neither leg seeded
        // genuinely has no rate, same as it would against the real Kraken
        // for an unlisted pair.
        $defaults = [
            ['BTC', 'EUR', 65000],
            ['BTC', 'USD', 70000],
            ['BTC', 'USDT', 65000],
            ['ETH', 'EUR', 3000],
            ['ETH', 'USD', 3200],
            ['ETH', 'USDT', 3000],
            ['USDT', 'EUR', 0.90],
            ['USDT', 'USD', 1.00],
        ];
        foreach ($defaults as [$base, $quote, $price]) {
            if (! KrakenPrice::where('base', $base)->where('quote', $quote)->exists()) {
                $pricer->set($base, $quote, $price, source: 'live');
            }
        }
    }

    private function seedVolatility(AssetVolatility $volatility): void
    {
        // Rough, illustrative figures, not calibrated against real
        // historical data — BTC/ETH wobble noticeably more than fiat or a
        // stablecoin does. A pair's own wobble combines both sides in
        // quadrature, see AssetVolatility::forPair().
        $defaults = ['BTC' => 1.5, 'ETH' => 2.0, 'EUR' => 0.05, 'USD' => 0.05, 'USDT' => 0.05];
        foreach ($defaults as $asset => $pct) {
            if (! KrakenAssetVolatility::find($asset)) {
                $volatility->set($asset, $pct);
            }
        }
    }

    private function seedDemoCredential(): void
    {
        if (KrakenApiCredential::exists()) {
            $this->command?->info('A kraken_api_credentials row already exists — not generating another.');

            return;
        }

        $apiKey = strtoupper(Str::random(28)).strtoupper(Str::random(28));
        $apiSecret = base64_encode(random_bytes(64));

        $credential = KrakenApiCredential::create([
            'label' => 'demo',
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ]);

        foreach (['EUR' => 100000, 'USD' => 100000, 'BTC' => 5, 'ETH' => 50, 'USDT' => 100000] as $asset => $amount) {
            KrakenBalance::create(['kraken_api_credential_id' => $credential->id, 'asset' => $asset, 'amount' => $amount]);
        }

        // Shown exactly once, same convention as every other "generated
        // secret" in this project's own ecosystem (Sanctum tokens, etc.)
        // — nothing re-displays api_secret after this run.
        $this->command?->warn('Generated a demo Kraken API credential — save these now, the secret is never shown again:');
        $this->command?->line("  API-Key:    {$apiKey}");
        $this->command?->line("  API-Secret: {$apiSecret}");
    }
}

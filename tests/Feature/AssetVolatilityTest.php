<?php

namespace Tests\Feature;

use App\Kraken\AssetVolatility;
use App\Kraken\Pricer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetVolatilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pair_wobbles_by_the_quadrature_combination_of_both_assets(): void
    {
        $volatility = app(AssetVolatility::class);
        $volatility->set('BTC', 3);
        $volatility->set('EUR', 4);

        // sqrt(3^2 + 4^2) = 5
        $this->assertSame(5.0, $volatility->forPair('BTC', 'EUR'));
    }

    public function test_an_unconfigured_asset_falls_back_to_the_default_rather_than_reading_as_dead_still(): void
    {
        $volatility = app(AssetVolatility::class);
        $volatility->set('BTC', 0);

        $this->assertGreaterThan(0, $volatility->forPair('BTC', 'SOMENEWCOIN'));
    }

    public function test_zeroing_both_assets_makes_a_pair_read_exactly_flat(): void
    {
        $volatility = app(AssetVolatility::class);
        $volatility->set('BTC', 0);
        $volatility->set('EUR', 0);
        app(Pricer::class)->set('BTC', 'EUR', 65000);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(65000.0, app(Pricer::class)->read('BTC', 'EUR'));
        }
    }

    public function test_the_internal_endpoint_changes_what_the_next_read_wobbles_by(): void
    {
        app(Pricer::class)->set('BTC', 'EUR', 65000);
        app(AssetVolatility::class)->set('EUR', 0);

        $this->postJson('/internal/asset-volatility', ['asset' => 'BTC', 'volatility_pct' => 50])->assertOk();

        $prices = [];
        for ($i = 0; $i < 30; $i++) {
            $prices[] = app(Pricer::class)->read('BTC', 'EUR');
        }
        // 50% volatility should produce visibly more spread than the
        // ~0.15% default this project seeds elsewhere.
        $this->assertGreaterThan(1000, max($prices) - min($prices));
    }
}

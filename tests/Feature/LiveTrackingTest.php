<?php

namespace Tests\Feature;

use App\Kraken\AssetVolatility;
use App\Kraken\Pricer;
use App\Models\KrakenPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * phpunit.xml sets KRAKEN_LIVE_UPSTREAM_URL='' for every other test, so a
 * 'live' pair never actually reaches the network — these are the only
 * tests that override it and fake Http, to exercise the real fetch path
 * in isolation.
 */
class LiveTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AssetVolatility::class)->set('BTC', 0);
        app(AssetVolatility::class)->set('EUR', 0);
        config(['kraken.live_upstream_url' => 'https://fake-kraken.test/0/public/Ticker']);
    }

    public function test_a_live_pair_refreshes_its_mid_price_from_the_real_kraken_response_shape(): void
    {
        app(Pricer::class)->set('BTC', 'EUR', 1, source: 'live');
        Http::fake(['fake-kraken.test/*' => Http::response([
            'error' => [],
            'result' => ['XXBTZEUR' => ['c' => ['99999.50000', '0.001']]],
        ])]);

        $mid = app(Pricer::class)->read('BTC', 'EUR');

        $this->assertSame(99999.5, $mid);
        $this->assertSame('live', KrakenPrice::where('base', 'BTC')->where('quote', 'EUR')->value('source'));
    }

    public function test_a_failed_live_fetch_falls_back_to_the_last_known_price_without_erroring(): void
    {
        app(Pricer::class)->set('BTC', 'EUR', 65000, source: 'live');
        Http::fake(['fake-kraken.test/*' => Http::response([], 503)]);

        $mid = app(Pricer::class)->read('BTC', 'EUR');

        $this->assertSame(65000.0, $mid);
    }

    public function test_a_manual_pair_is_never_refreshed_from_the_real_kraken(): void
    {
        app(Pricer::class)->set('BTC', 'EUR', 65000); // defaults to manual
        Http::fake(function () {
            $this->fail('a manual pair must never call out to the live upstream');
        });

        $mid = app(Pricer::class)->read('BTC', 'EUR');

        $this->assertSame(65000.0, $mid);
    }

    public function test_a_stale_live_pair_refetches_but_a_fresh_one_does_not(): void
    {
        app(Pricer::class)->set('BTC', 'EUR', 1, source: 'live');
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => [], 'result' => ['XXBTZEUR' => ['c' => ['50000.0', '0.001']]]]);
        });

        app(Pricer::class)->read('BTC', 'EUR'); // stale (never synced) -> fetches
        app(Pricer::class)->read('BTC', 'EUR'); // just synced -> doesn't fetch again

        $this->assertSame(1, $calls);
    }
}

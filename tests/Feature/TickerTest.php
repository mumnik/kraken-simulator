<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticker_returns_a_price_for_a_seeded_pair(): void
    {
        $this->seed();

        $res = $this->getJson('/0/public/Ticker?pair=XBTEUR');

        $res->assertOk()->assertJsonPath('error', []);
        $this->assertArrayHasKey('XXBTZEUR', $res->json('result'));
        $this->assertNotEmpty($res->json('result.XXBTZEUR.c.0'));
    }

    public function test_ticker_accepts_multiple_comma_separated_pairs(): void
    {
        $this->seed();

        $res = $this->getJson('/0/public/Ticker?pair=XBTEUR,ETHUSD');

        $res->assertOk();
        $result = $res->json('result');
        $this->assertArrayHasKey('XXBTZEUR', $result);
        $this->assertArrayHasKey('XETHZUSD', $result);
    }

    public function test_ticker_rejects_an_unknown_pair_the_kraken_shaped_way(): void
    {
        $this->seed();

        $res = $this->getJson('/0/public/Ticker?pair=DOGEEUR');

        // Real Kraken answers a business error with HTTP 200, not a 4xx —
        // see VerifyKrakenSignature's own docblock for why that convention
        // matters here too.
        $res->assertOk()->assertJsonPath('error.0', 'EQuery:Unknown asset pair');
    }

    public function test_repeated_reads_wobble_around_the_stored_mid_price_not_away_from_it(): void
    {
        $this->seed();

        $prices = [];
        for ($i = 0; $i < 20; $i++) {
            $prices[] = (float) $this->getJson('/0/public/Ticker?pair=XBTEUR')->json('result.XXBTZEUR.c.0');
        }

        // Default seeded volatility is 0.15% — every read should land
        // within a couple of multiples of that around the seeded 65000.
        foreach ($prices as $p) {
            $this->assertEqualsWithDelta(65000, $p, 65000 * 0.01);
        }
        // Not all 20 reads landed on the exact same number — the jitter is
        // actually doing something, not a no-op.
        $this->assertGreaterThan(1, count(array_unique($prices)));
    }
}

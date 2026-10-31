<?php

namespace Tests\Feature;

use App\Models\KrakenPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_routes_are_open_when_no_token_is_configured(): void
    {
        config(['kraken.internal_token' => '']);

        $this->postJson('/internal/prices', ['base' => 'BTC', 'quote' => 'EUR', 'mid_price' => 70000])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('70000.0000000000', (string) KrakenPrice::where('base', 'BTC')->where('quote', 'EUR')->value('mid_price'));
    }

    public function test_internal_routes_require_the_configured_bearer_token(): void
    {
        config(['kraken.internal_token' => 'secret-token']);

        $this->postJson('/internal/prices', ['base' => 'BTC', 'quote' => 'EUR', 'mid_price' => 70000])
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer secret-token')
            ->postJson('/internal/prices', ['base' => 'BTC', 'quote' => 'EUR', 'mid_price' => 70000])
            ->assertOk();
    }

    public function test_setting_a_price_is_what_the_next_ticker_read_wobbles_around(): void
    {
        $this->postJson('/internal/prices', ['base' => 'BTC', 'quote' => 'EUR', 'mid_price' => 123456])
            ->assertOk();
        $this->postJson('/internal/asset-volatility', ['asset' => 'BTC', 'volatility_pct' => 0])->assertOk();
        $this->postJson('/internal/asset-volatility', ['asset' => 'EUR', 'volatility_pct' => 0])->assertOk();

        $this->getJson('/0/public/Ticker?pair=XBTEUR')
            ->assertJsonPath('result.XXBTZEUR.c.0', '123456.0000000000');
    }

    public function test_setting_a_price_switches_the_pair_to_manual_and_live_switches_it_back(): void
    {
        $this->postJson('/internal/prices', ['base' => 'BTC', 'quote' => 'EUR', 'mid_price' => 70000])->assertOk();
        $this->assertSame('manual', KrakenPrice::where('base', 'BTC')->where('quote', 'EUR')->value('source'));

        $this->postJson('/internal/prices/live', ['base' => 'BTC', 'quote' => 'EUR'])->assertOk();
        $this->assertSame('live', KrakenPrice::where('base', 'BTC')->where('quote', 'EUR')->value('source'));
    }
}

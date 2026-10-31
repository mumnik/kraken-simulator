<?php

namespace Tests\Feature;

use App\Kraken\Pricer;
use App\Models\KrakenOrder;
use App\Models\KrakenSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsKrakenRequests;
use Tests\TestCase;

class AddOrderTest extends TestCase
{
    use RefreshDatabase, SignsKrakenRequests;

    protected function setUp(): void
    {
        parent::setUp();
        app(Pricer::class)->set('BTC', 'EUR', 65000);
        // Zero volatility here — these tests check exact fill/margin
        // arithmetic; TickerTest is where the jitter itself gets covered.
        KrakenSetting::current()->update(['volatility_pct' => 0]);
    }

    public function test_a_market_sell_fills_immediately_and_moves_both_balances(): void
    {
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);

        $res = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ]);

        $res->assertOk()->assertJsonPath('error', []);
        $txid = $res->json('result.txid.0');
        $this->assertNotEmpty($txid);

        $this->assertSame(0.5, $credential->fresh()->balanceOf('BTC'));
        $this->assertSame(32500.0, $credential->fresh()->balanceOf('EUR')); // 0.5 * 65000

        $order = KrakenOrder::where('txid', $txid)->firstOrFail();
        $this->assertSame('closed', $order->status);
    }

    public function test_insufficient_funds_is_rejected_and_logged_without_moving_any_balance(): void
    {
        $credential = $this->makeCredential(['BTC' => 0.1, 'EUR' => 0]);

        $res = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '1',
        ]);

        $res->assertOk()->assertJsonPath('error.0', 'EOrder:Insufficient funds');
        $this->assertSame(0.1, $credential->fresh()->balanceOf('BTC'));

        $logged = KrakenOrder::where('kraken_api_credential_id', $credential->id)->first();
        $this->assertSame('rejected', $logged->status);
        $this->assertSame('EOrder:Insufficient funds', $logged->reject_reason);
    }

    public function test_leveraged_order_checks_margin_in_the_quote_asset_not_the_full_notional(): void
    {
        // 1 BTC @ 65000 EUR notional at 10x leverage needs 6500 EUR margin —
        // 7000 EUR covers that even though it's nowhere near the full notional.
        $credential = $this->makeCredential(['EUR' => 7000]);

        $res = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'buy', 'ordertype' => 'market', 'volume' => '1', 'leverage' => '10',
        ]);

        $res->assertOk()->assertJsonPath('error', []);
        $this->assertSame(500.0, $credential->fresh()->balanceOf('EUR')); // 7000 - 6500
    }

    public function test_a_resting_limit_order_away_from_market_does_not_fill_or_move_balance(): void
    {
        $credential = $this->makeCredential(['EUR' => 100000]);

        // Market is 65000 — a buy limit at 50000 doesn't cross, it rests.
        $res = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'buy', 'ordertype' => 'limit', 'volume' => '1', 'price' => '50000',
        ]);

        $res->assertOk()->assertJsonPath('error', []);
        $this->assertSame(100000.0, $credential->fresh()->balanceOf('EUR'));

        $txid = $res->json('result.txid.0');
        $this->assertSame('open', KrakenOrder::where('txid', $txid)->value('status'));
    }

    public function test_an_open_order_can_be_cancelled(): void
    {
        $credential = $this->makeCredential(['EUR' => 100000]);
        $txid = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'buy', 'ordertype' => 'limit', 'volume' => '1', 'price' => '50000',
        ])->json('result.txid.0');

        $res = $this->signedPost($credential, '/0/private/CancelOrder', ['txid' => $txid]);

        $res->assertOk()->assertJsonPath('result.count', 1);
        $this->assertSame('canceled', KrakenOrder::where('txid', $txid)->value('status'));
    }

    public function test_random_fail_probability_rejects_an_otherwise_valid_order(): void
    {
        KrakenSetting::current()->update(['random_fail_probability' => 1]); // always
        $credential = $this->makeCredential(['BTC' => 1]);

        $res = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.1',
        ]);

        $res->assertOk();
        $this->assertNotSame([], $res->json('error'));
        $this->assertNotSame('EOrder:Insufficient funds', $res->json('error.0')); // had the funds — this is the injected kind
        // Balance untouched — a rejected order never partially executes.
        $this->assertSame(1.0, $credential->fresh()->balanceOf('BTC'));
    }

    public function test_unknown_pair_is_rejected_without_being_logged(): void
    {
        $credential = $this->makeCredential(['EUR' => 100]);

        $res = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'DOGEEUR', 'type' => 'buy', 'ordertype' => 'market', 'volume' => '1',
        ]);

        $res->assertOk()->assertJsonPath('error.0', 'EQuery:Unknown asset pair');
        $this->assertSame(0, KrakenOrder::count());
    }
}

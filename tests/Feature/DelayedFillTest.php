<?php

namespace Tests\Feature;

use App\Kraken\AssetVolatility;
use App\Kraken\Pricer;
use App\Models\KrakenOrder;
use App\Models\KrakenSetting;
use App\Models\KrakenWebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsKrakenRequests;
use Tests\TestCase;

class DelayedFillTest extends TestCase
{
    use RefreshDatabase, SignsKrakenRequests;

    protected function setUp(): void
    {
        parent::setUp();
        app(Pricer::class)->set('BTC', 'EUR', 65000);
        app(AssetVolatility::class)->set('BTC', 0);
        app(AssetVolatility::class)->set('EUR', 0);
        KrakenSetting::current()->update(['fill_delay_seconds' => 30]);
    }

    public function test_a_delayed_order_stays_open_and_balance_untouched_right_after_add_order(): void
    {
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);

        $res = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ]);

        $res->assertOk()->assertJsonPath('error', []);
        $txid = $res->json('result.txid.0');
        $order = KrakenOrder::where('txid', $txid)->firstOrFail();
        $this->assertSame('open', $order->status);
        $this->assertNotNull($order->closes_at);
        $this->assertNotNull($order->price); // locked in at submission, not yet applied
        $this->assertSame(1.0, $credential->fresh()->balanceOf('BTC'));
        $this->assertSame(0.0, $credential->fresh()->balanceOf('EUR'));
    }

    public function test_query_orders_closes_it_once_due_and_moves_the_balance(): void
    {
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);
        $txid = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ])->json('result.txid.0');

        // Not due yet — still open.
        $this->signedPost($credential, '/0/private/QueryOrders', ['txid' => $txid])
            ->assertJsonPath("result.{$txid}.status", 'open');
        $this->assertSame(1.0, $credential->fresh()->balanceOf('BTC'));

        $this->travel(31)->seconds();

        $res = $this->signedPost($credential, '/0/private/QueryOrders', ['txid' => $txid]);
        $res->assertJsonPath("result.{$txid}.status", 'closed');
        $this->assertSame(0.5, $credential->fresh()->balanceOf('BTC'));
        $this->assertSame(32500.0, $credential->fresh()->balanceOf('EUR'));
    }

    public function test_open_orders_also_finalizes_a_due_order(): void
    {
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);
        $txid = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ])->json('result.txid.0');

        $this->travel(31)->seconds();

        // A finalized order no longer shows up as "open".
        $this->signedPost($credential, '/0/private/OpenOrders', [])
            ->assertJsonMissingPath("result.open.{$txid}");
        $this->assertSame(0.5, $credential->fresh()->balanceOf('BTC'));
    }

    public function test_the_filled_webhook_fires_on_finalization_not_on_submission(): void
    {
        KrakenWebhookEndpoint::create([
            'url' => 'https://example.test/hook', 'secret' => 'x', 'events' => ['order.filled'], 'active' => true,
        ]);
        Http::fake(['example.test/*' => Http::response(['ok' => true], 200)]);
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);

        $txid = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ])->json('result.txid.0');
        Http::assertNothingSent();

        $this->travel(31)->seconds();
        $this->signedPost($credential, '/0/private/QueryOrders', ['txid' => $txid]);

        Http::assertSent(fn ($r) => json_decode($r->body(), true)['event'] === 'order.filled');
    }

    public function test_a_resting_limit_order_away_from_market_is_unaffected_by_the_delay(): void
    {
        $credential = $this->makeCredential(['EUR' => 100000]);

        $res = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'buy', 'ordertype' => 'limit', 'volume' => '1', 'price' => '50000',
        ]);

        $txid = $res->json('result.txid.0');
        $order = KrakenOrder::where('txid', $txid)->firstOrFail();
        $this->assertSame('open', $order->status);
        $this->assertNull($order->closes_at); // never auto-closes — only CancelOrder resolves it

        $this->travel(1)->hours();
        $this->signedPost($credential, '/0/private/QueryOrders', ['txid' => $txid])
            ->assertJsonPath("result.{$txid}.status", 'open');
    }

    public function test_fill_delay_zero_keeps_the_original_instant_behavior(): void
    {
        KrakenSetting::current()->update(['fill_delay_seconds' => 0]);
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);

        $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ])->assertJsonPath('error', []);

        $this->assertSame(0.5, $credential->fresh()->balanceOf('BTC'));
    }
}

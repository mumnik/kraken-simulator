<?php

namespace Tests\Feature;

use App\Kraken\AssetVolatility;
use App\Kraken\Pricer;
use App\Models\KrakenWebhookDelivery;
use App\Models\KrakenWebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsKrakenRequests;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase, SignsKrakenRequests;

    protected function setUp(): void
    {
        parent::setUp();
        app(Pricer::class)->set('BTC', 'EUR', 65000);
        app(AssetVolatility::class)->set('BTC', 0);
        app(AssetVolatility::class)->set('EUR', 0);
    }

    private function registerWebhook(array $events, ?string $apiKey = null): array
    {
        $payload = array_filter(['url' => 'https://example.test/hook', 'events' => $events, 'api_key' => $apiKey]);

        return $this->postJson('/internal/webhooks', $payload)->assertStatus(201)->json('data');
    }

    public function test_a_filled_order_fires_a_correctly_signed_order_filled_webhook(): void
    {
        $hook = $this->registerWebhook(['order.filled']);
        Http::fake(['example.test/*' => Http::response(['ok' => true], 200)]);
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);

        $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ])->assertOk()->assertJsonPath('error', []);

        Http::assertSent(function ($request) use ($hook) {
            $signature = $request->header('X-Kraken-Simulator-Signature')[0] ?? null;
            $expected = hash_hmac('sha256', $request->body(), $hook['secret']);

            return $request->url() === 'https://example.test/hook'
                && $signature === $expected
                && json_decode($request->body(), true)['event'] === 'order.filled';
        });

        $delivery = KrakenWebhookDelivery::where('event', 'order.filled')->firstOrFail();
        $this->assertSame(200, $delivery->response_status);
    }

    public function test_a_rejected_order_fires_order_rejected_not_order_filled(): void
    {
        $this->registerWebhook(['order.filled', 'order.rejected']);
        Http::fake(['example.test/*' => Http::response(['ok' => true], 200)]);
        $credential = $this->makeCredential(['BTC' => 0.1, 'EUR' => 0]);

        $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '1',
        ])->assertOk()->assertJsonPath('error.0', 'EOrder:Insufficient funds');

        $this->assertSame(1, KrakenWebhookDelivery::where('event', 'order.rejected')->count());
        $this->assertSame(0, KrakenWebhookDelivery::where('event', 'order.filled')->count());
    }

    public function test_a_cancelled_order_fires_order_canceled(): void
    {
        $this->registerWebhook(['order.canceled']);
        Http::fake(['example.test/*' => Http::response(['ok' => true], 200)]);
        $credential = $this->makeCredential(['EUR' => 100000]);

        $txid = $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'buy', 'ordertype' => 'limit', 'volume' => '1', 'price' => '50000',
        ])->json('result.txid.0');
        $this->signedPost($credential, '/0/private/CancelOrder', ['txid' => $txid])->assertOk();

        $this->assertSame(1, KrakenWebhookDelivery::where('event', 'order.canceled')->count());
    }

    public function test_a_webhook_scoped_to_one_credential_does_not_fire_for_another(): void
    {
        $other = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);
        $this->registerWebhook(['order.filled'], apiKey: $other->api_key);
        Http::fake(['example.test/*' => Http::response(['ok' => true], 200)]);
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);

        $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ])->assertOk()->assertJsonPath('error', []);

        Http::assertNothingSent();
        $this->assertSame(0, KrakenWebhookDelivery::count());
    }

    public function test_a_broken_endpoint_is_recorded_but_never_fails_the_order_itself(): void
    {
        $this->registerWebhook(['order.filled']);
        Http::fake(['example.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);

        $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ])->assertOk()->assertJsonPath('error', []);

        $delivery = KrakenWebhookDelivery::where('event', 'order.filled')->firstOrFail();
        $this->assertNull($delivery->response_status);
        $this->assertNotNull($delivery->error);
    }

    public function test_deleting_a_webhook_stops_further_deliveries(): void
    {
        $hook = $this->registerWebhook(['order.filled']);
        KrakenWebhookEndpoint::find($hook['id'])->delete();
        Http::fake(['example.test/*' => Http::response(['ok' => true], 200)]);
        $credential = $this->makeCredential(['BTC' => 1, 'EUR' => 0]);

        $this->signedPost($credential, '/0/private/AddOrder', [
            'pair' => 'XBTEUR', 'type' => 'sell', 'ordertype' => 'market', 'volume' => '0.5',
        ])->assertOk();

        Http::assertNothingSent();
    }
}

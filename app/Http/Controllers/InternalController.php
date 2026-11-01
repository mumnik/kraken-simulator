<?php

namespace App\Http\Controllers;

use App\Kraken\AssetVolatility;
use App\Kraken\Pricer;
use App\Models\KrakenApiCredential;
use App\Models\KrakenAssetVolatility;
use App\Models\KrakenBalance;
use App\Models\KrakenOrder;
use App\Models\KrakenPrice;
use App\Models\KrakenSetting;
use App\Models\KrakenWebhookDelivery;
use App\Models\KrakenWebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * This simulator's own control surface — not part of Kraken's real
 * protocol at all (see VerifyBearerToken's own docblock on why that's a
 * deliberately separate trust boundary from the signed /0/private/*
 * routes). What a test or a demo reaches for to engineer a specific
 * scenario: force a price (and so a specific R1-vs-R2 drift), top up a
 * demo account's balance, dial in how often an otherwise-valid order
 * gets randomly rejected, tune how much a given asset wobbles, or
 * register something to be told when an order fills/fails.
 */
class InternalController extends Controller
{
    public function __construct(
        private readonly Pricer $pricer,
        private readonly AssetVolatility $volatility,
    ) {
    }

    public function setPrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base' => ['required', 'string'],
            'quote' => ['required', 'string'],
            'mid_price' => ['required', 'numeric', 'gt:0'],
        ]);
        // Always 'manual' — an explicit override holds until
        // POST /internal/prices/live asks for it back, see Pricer::set().
        $this->pricer->set($data['base'], $data['quote'], (float) $data['mid_price']);

        return response()->json(['ok' => true]);
    }

    public function goLive(Request $request): JsonResponse
    {
        $data = $request->validate(['base' => ['required', 'string'], 'quote' => ['required', 'string']]);
        $this->pricer->goLive($data['base'], $data['quote']);

        return response()->json(['ok' => true]);
    }

    public function listPrices(): JsonResponse
    {
        return response()->json(['data' => KrakenPrice::all(['base', 'quote', 'mid_price', 'source', 'live_synced_at'])]);
    }

    public function setBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['required', 'string'],
            'asset' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gte:0'],
        ]);
        $credential = KrakenApiCredential::where('api_key', $data['api_key'])->firstOrFail();
        KrakenBalance::updateOrCreate(
            ['kraken_api_credential_id' => $credential->id, 'asset' => strtoupper($data['asset'])],
            ['amount' => $data['amount']],
        );

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        $setting = KrakenSetting::current();
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'random_fail_probability' => ['sometimes', 'numeric', 'between:0,1'],
                'fill_delay_seconds' => ['sometimes', 'integer', 'min:0'],
            ]);
            $setting->update($data);
        }

        return response()->json(['data' => $setting->only(['random_fail_probability', 'fill_delay_seconds'])]);
    }

    public function setAssetVolatility(Request $request): JsonResponse
    {
        $data = $request->validate(['asset' => ['required', 'string'], 'volatility_pct' => ['required', 'numeric', 'gte:0']]);
        $this->volatility->set($data['asset'], (float) $data['volatility_pct']);

        return response()->json(['ok' => true]);
    }

    public function listAssetVolatility(): JsonResponse
    {
        return response()->json(['data' => KrakenAssetVolatility::all(['asset', 'volatility_pct'])]);
    }

    public function credentials(): JsonResponse
    {
        return response()->json(['data' => KrakenApiCredential::with('balances')->get()->map(fn ($c) => [
            'label' => $c->label,
            'api_key' => $c->api_key,
            'balances' => $c->balances->mapWithKeys(fn ($b) => [$b->asset => (string) $b->amount]),
        ])]);
    }

    public function orders(): JsonResponse
    {
        return response()->json(['data' => KrakenOrder::latest('id')->limit(100)->get([
            'txid', 'pair', 'type', 'ordertype', 'volume', 'price', 'status', 'reject_reason', 'opened_at', 'closed_at',
        ])]);
    }

    public function createWebhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['sometimes', 'string'], // omit to fire for every credential
            'url' => ['required', 'url'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:order.filled,order.rejected,order.canceled'],
        ]);
        $credentialId = isset($data['api_key'])
            ? KrakenApiCredential::where('api_key', $data['api_key'])->firstOrFail()->id
            : null;

        $endpoint = KrakenWebhookEndpoint::create([
            'kraken_api_credential_id' => $credentialId,
            'url' => $data['url'],
            'secret' => bin2hex(random_bytes(32)),
            'events' => $data['events'],
        ]);

        // Shown exactly once, same convention as every other generated
        // secret in this project's own ecosystem — verify incoming
        // deliveries against the X-Kraken-Simulator-Signature header now,
        // it's never displayed again.
        return response()->json(['data' => ['id' => $endpoint->id, 'secret' => $endpoint->secret]], 201);
    }

    public function listWebhooks(): JsonResponse
    {
        return response()->json(['data' => KrakenWebhookEndpoint::with('credential:id,api_key,label')
            ->get(['id', 'kraken_api_credential_id', 'url', 'events', 'active'])]);
    }

    public function deleteWebhook(int $id): JsonResponse
    {
        KrakenWebhookEndpoint::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function webhookDeliveries(): JsonResponse
    {
        return response()->json(['data' => KrakenWebhookDelivery::with('endpoint:id,url')
            ->latest('id')->limit(100)
            ->get(['id', 'kraken_webhook_endpoint_id', 'event', 'response_status', 'error', 'created_at'])]);
    }
}

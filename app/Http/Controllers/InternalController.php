<?php

namespace App\Http\Controllers;

use App\Kraken\Pricer;
use App\Models\KrakenApiCredential;
use App\Models\KrakenBalance;
use App\Models\KrakenOrder;
use App\Models\KrakenSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * This simulator's own control surface — not part of Kraken's real
 * protocol at all (see VerifyBearerToken's own docblock on why that's a
 * deliberately separate trust boundary from the signed /0/private/*
 * routes). What a test or a demo reaches for to engineer a specific
 * scenario: force a price (and so a specific R1-vs-R2 drift), top up a
 * demo account's balance, or dial in how often an otherwise-valid order
 * gets randomly rejected.
 */
class InternalController extends Controller
{
    public function __construct(private readonly Pricer $pricer) {}

    public function setPrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base' => ['required', 'string'],
            'quote' => ['required', 'string'],
            'mid_price' => ['required', 'numeric', 'gt:0'],
        ]);
        $this->pricer->set($data['base'], $data['quote'], (float) $data['mid_price']);

        return response()->json(['ok' => true]);
    }

    public function listPrices(): JsonResponse
    {
        return response()->json(['data' => \App\Models\KrakenPrice::all(['base', 'quote', 'mid_price'])]);
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
                'volatility_pct' => ['sometimes', 'numeric', 'gte:0'],
                'random_fail_probability' => ['sometimes', 'numeric', 'between:0,1'],
            ]);
            $setting->update($data);
        }

        return response()->json(['data' => $setting->only(['volatility_pct', 'random_fail_probability'])]);
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
}

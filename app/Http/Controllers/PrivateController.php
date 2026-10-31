<?php

namespace App\Http\Controllers;

use App\Kraken\AssetCodes;
use App\Kraken\OrderEngine;
use App\Kraken\Pricer;
use App\Models\KrakenApiCredential;
use App\Models\KrakenOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed surface — /0/private/*, behind VerifyKrakenSignature (see
 * that class for why every response here is a 200 with {"error": [...]}
 * rather than an HTTP error status, successes included).
 */
class PrivateController extends Controller
{
    public function __construct(
        private readonly OrderEngine $orders,
        private readonly Pricer $pricer,
    ) {
    }

    public function balance(Request $request): JsonResponse
    {
        $credential = $this->credential($request);
        $balances = $credential->balances()->where('amount', '>', 0)->get();

        $result = [];
        foreach ($balances as $b) {
            $result[AssetCodes::internalCode($b->asset)] = number_format((float) $b->amount, 10, '.', '');
        }

        return response()->json(['error' => [], 'result' => $result]);
    }

    /**
     * Simplified — not real margin accounting (see OrderEngine's own
     * docblock). `eb`/`tb`/`e` are all the same number here: every
     * balance's value converted to EUR at the current (jittered) price.
     * `mf` (free margin) is that same figure too, since this simulator
     * doesn't track margin already in use separately from plain balance.
     */
    public function tradeBalance(Request $request): JsonResponse
    {
        $credential = $this->credential($request);
        $total = 0.0;
        foreach ($credential->balances as $b) {
            $asset = strtoupper($b->asset);
            $amount = (float) $b->amount;
            $total += $asset === 'EUR' ? $amount : ($this->pricer->exists($asset, 'EUR') ? $amount * $this->pricer->read($asset, 'EUR') : 0.0);
        }
        $value = number_format($total, 10, '.', '');

        return response()->json(['error' => [], 'result' => ['eb' => $value, 'tb' => $value, 'e' => $value, 'mf' => $value]]);
    }

    public function addOrder(Request $request): JsonResponse
    {
        $credential = $this->credential($request);
        $result = $this->orders->addOrder($credential, $request->only(['pair', 'type', 'ordertype', 'volume', 'price', 'leverage']));

        if (! $result->ok) {
            return response()->json(['error' => [$result->errorCode], 'result' => []]);
        }

        $order = $result->order;

        return response()->json(['error' => [], 'result' => [
            'descr' => ['order' => "{$order->type} {$order->volume} {$order->pair} @ {$order->ordertype}".($order->price ? " {$order->price}" : '')],
            'txid' => [$order->txid],
        ]]);
    }

    public function cancelOrder(Request $request): JsonResponse
    {
        $credential = $this->credential($request);
        $txid = (string) $request->input('txid', '');
        $ok = $this->orders->cancelOrder($credential, $txid);

        if (! $ok) {
            return response()->json(['error' => ['EOrder:Unknown order'], 'result' => []]);
        }

        return response()->json(['error' => [], 'result' => ['count' => 1]]);
    }

    public function openOrders(Request $request): JsonResponse
    {
        $credential = $this->credential($request);
        $open = $credential->orders()->where('status', 'open')->get();

        return response()->json(['error' => [], 'result' => ['open' => $this->ordersById($open)]]);
    }

    public function queryOrders(Request $request): JsonResponse
    {
        $credential = $this->credential($request);
        $txids = array_filter(explode(',', (string) $request->input('txid', '')));
        $orders = $credential->orders()->whereIn('txid', $txids)->get();

        return response()->json(['error' => [], 'result' => $this->ordersById($orders)]);
    }

    private function credential(Request $request): KrakenApiCredential
    {
        /** @var KrakenApiCredential */
        return $request->attributes->get('kraken_credential');
    }

    /** @param \Illuminate\Support\Collection<int, KrakenOrder> $orders */
    private function ordersById($orders): array
    {
        $byId = [];
        foreach ($orders as $o) {
            $byId[$o->txid] = [
                'status' => $o->status,
                'descr' => ['pair' => $o->pair, 'type' => $o->type, 'ordertype' => $o->ordertype],
                'vol' => number_format((float) $o->volume, 10, '.', ''),
                'price' => $o->price !== null ? number_format((float) $o->price, 10, '.', '') : '0',
                'opentm' => $o->opened_at->timestamp,
                'closetm' => $o->closed_at?->timestamp,
            ];
        }

        return $byId;
    }
}

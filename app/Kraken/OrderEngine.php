<?php

namespace App\Kraken;

use App\Models\KrakenApiCredential;
use App\Models\KrakenBalance;
use App\Models\KrakenOrder;
use App\Models\KrakenSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AddOrder's real logic — validation, balance/margin check, a chance of
 * injected transient failure, and (on success) the balance move itself.
 * Every rejection reason is a real Kraken error code
 * (https://docs.kraken.com/api/docs/rest-api/add-order's own error list),
 * not a made-up one, so error-handling code written against this behaves
 * the same way it would against the real exchange.
 *
 * Every attempt past "the pair is at least real" is logged to kraken_orders
 * — rejected ones too (status=rejected, reject_reason set) — so
 * GET /internal/orders can answer "why did that fail" during a demo or a
 * test, not just show what actually filled. A request too malformed to
 * even identify a pair (unknown pair, garbage type/ordertype) isn't logged
 * — there's nothing meaningful to show against, and it indicates a client
 * bug rather than a market/account condition worth reviewing later.
 *
 * Leverage is modeled as a simplification, not Kraken's real margin
 * engine: a leveraged order's required margin (notional / leverage) is
 * checked and deducted straight from the quote asset's own balance — there
 * is no separate running "margin used" ledger across open positions, and
 * closing/unwinding a leveraged position isn't modeled at all yet. Good
 * enough to exercise "insufficient margin" as a distinct path from
 * "insufficient funds" on a plain spot order; not a real margin system.
 *
 * Every terminal outcome (filled, rejected, canceled) also fires a
 * WebhookDispatcher event of the same name — see that class's own
 * docblock for why, and why it happens outside the DB transaction below.
 */
class OrderEngine
{
    private const RANDOM_FAILURE_CODES = [
        'EService:Unavailable',
        'EOrder:Cannot open position',
        'EAPI:Rate limit exceeded',
        'EGeneral:Temporary lockout',
    ];

    // Below this volume of the base asset, a real exchange's own per-pair
    // minimum would reject it — one flat figure here, not per-pair, since
    // this simulator doesn't model wildly different minimums per asset.
    private const MIN_VOLUME = 0.0001;

    public function __construct(
        private readonly Pricer $pricer,
        private readonly WebhookDispatcher $webhooks,
    ) {
    }

    /**
     * @param array{pair: string, type: string, ordertype: string, volume: string, price?: string, leverage?: string} $params
     */
    public function addOrder(KrakenApiCredential $credential, array $params): OrderResult
    {
        $pair = AssetCodes::parsePair((string) ($params['pair'] ?? ''));
        if ($pair === null || ! $this->pricer->exists($pair[0], $pair[1])) {
            return OrderResult::failure('EQuery:Unknown asset pair');
        }
        [$base, $quote] = $pair;
        $pairLabel = "{$base}/{$quote}";

        $type = strtolower((string) ($params['type'] ?? ''));
        if (! in_array($type, ['buy', 'sell'], true)) {
            return OrderResult::failure('EGeneral:Invalid arguments');
        }

        $ordertype = strtolower((string) ($params['ordertype'] ?? ''));
        if (! in_array($ordertype, ['market', 'limit'], true)) {
            return OrderResult::failure('EGeneral:Invalid arguments');
        }

        $volume = is_numeric($params['volume'] ?? null) ? (float) $params['volume'] : null;
        if ($volume === null || $volume <= 0) {
            return OrderResult::failure('EGeneral:Invalid arguments');
        }
        if ($volume < self::MIN_VOLUME) {
            return $this->reject($credential, $pairLabel, $type, $ordertype, $volume, null, null, 'EOrder:Order minimum not met');
        }

        $limitPrice = null;
        if ($ordertype === 'limit') {
            $limitPrice = is_numeric($params['price'] ?? null) ? (float) $params['price'] : null;
            if ($limitPrice === null || $limitPrice <= 0) {
                return $this->reject($credential, $pairLabel, $type, $ordertype, $volume, null, null, 'EOrder:Invalid price');
            }
        }

        $leverage = null;
        if (! empty($params['leverage']) && $params['leverage'] !== 'none') {
            $leverage = is_numeric($params['leverage']) ? (float) $params['leverage'] : null;
            if ($leverage === null || $leverage < 1) {
                return $this->reject($credential, $pairLabel, $type, $ordertype, $volume, $limitPrice, null, 'EGeneral:Invalid arguments');
            }
        }

        $marketPrice = $this->pricer->read($base, $quote);
        // A resting limit order: a buy below the market, or a sell above
        // it, doesn't fill yet — it stays open until CancelOrder (there's
        // no background price walk that would later touch it here).
        $fillsNow = $ordertype === 'market'
            || ($type === 'buy' && $limitPrice >= $marketPrice)
            || ($type === 'sell' && $limitPrice <= $marketPrice);
        $fillPrice = $ordertype === 'market' ? $marketPrice : $limitPrice;

        if ($fillsNow) {
            $notional = $volume * $fillPrice;
            [$requiredAsset, $requiredAmount] = $leverage !== null
                ? [$quote, $notional / $leverage]
                : ($type === 'sell' ? [$base, $volume] : [$quote, $notional]);

            if ($credential->balanceOf($requiredAsset) < $requiredAmount) {
                return $this->reject($credential, $pairLabel, $type, $ordertype, $volume, $limitPrice, $leverage, 'EOrder:Insufficient funds');
            }
        }

        // Only an order that would otherwise genuinely succeed can hit
        // this — real exchanges don't randomly bless an order that was
        // always going to fail anyway.
        $failProbability = (float) KrakenSetting::current()->random_fail_probability;
        if ($failProbability > 0 && mt_rand() / mt_getrandmax() < $failProbability) {
            $code = self::RANDOM_FAILURE_CODES[array_rand(self::RANDOM_FAILURE_CODES)];

            return $this->reject($credential, $pairLabel, $type, $ordertype, $volume, $limitPrice, $leverage, $code);
        }

        $order = DB::transaction(function () use ($credential, $base, $quote, $pairLabel, $type, $ordertype, $volume, $fillPrice, $limitPrice, $leverage, $fillsNow) {
            if ($fillsNow) {
                $notional = $volume * $fillPrice;
                if ($leverage !== null) {
                    $this->adjustBalance($credential, $quote, -($notional / $leverage));
                } elseif ($type === 'sell') {
                    $this->adjustBalance($credential, $base, -$volume);
                    $this->adjustBalance($credential, $quote, $notional);
                } else {
                    $this->adjustBalance($credential, $quote, -$notional);
                    $this->adjustBalance($credential, $base, $volume);
                }
            }

            return KrakenOrder::create([
                'kraken_api_credential_id' => $credential->id,
                'txid' => $this->newTxid(),
                'pair' => $pairLabel,
                'type' => $type,
                'ordertype' => $ordertype,
                'volume' => $volume,
                'price' => $fillsNow ? $fillPrice : $limitPrice,
                'leverage' => $leverage,
                'status' => $fillsNow ? 'closed' : 'open',
                'opened_at' => now(),
                'closed_at' => $fillsNow ? now() : null,
            ]);
        });

        // Outside the transaction, deliberately — an HTTP call to a
        // webhook endpoint shouldn't hold the DB transaction (and, on
        // sqlite, its write lock) open for however long that takes.
        if ($order->status === 'closed') {
            $this->webhooks->dispatch('order.filled', $this->orderPayload($order), $credential->id);
        }

        return OrderResult::success($order);
    }

    public function cancelOrder(KrakenApiCredential $credential, string $txid): bool
    {
        $order = KrakenOrder::where('kraken_api_credential_id', $credential->id)
            ->where('txid', $txid)->where('status', 'open')->first();
        if ($order === null) {
            return false;
        }
        $order->update(['status' => 'canceled', 'closed_at' => now()]);
        $this->webhooks->dispatch('order.canceled', $this->orderPayload($order), $credential->id);

        return true;
    }

    /** @return array<string, mixed> */
    private function orderPayload(KrakenOrder $order): array
    {
        return [
            'txid' => $order->txid,
            'pair' => $order->pair,
            'type' => $order->type,
            'ordertype' => $order->ordertype,
            'volume' => (string) $order->volume,
            'price' => $order->price !== null ? (string) $order->price : null,
            'status' => $order->status,
            'reject_reason' => $order->reject_reason,
        ];
    }

    private function reject(
        KrakenApiCredential $credential,
        string $pairLabel,
        string $type,
        string $ordertype,
        float $volume,
        ?float $price,
        ?float $leverage,
        string $code,
    ): OrderResult {
        $order = KrakenOrder::create([
            'kraken_api_credential_id' => $credential->id,
            'txid' => $this->newTxid(),
            'pair' => $pairLabel,
            'type' => $type,
            'ordertype' => $ordertype,
            'volume' => $volume,
            'price' => $price,
            'leverage' => $leverage,
            'status' => 'rejected',
            'reject_reason' => $code,
            'opened_at' => now(),
            'closed_at' => now(),
        ]);
        $this->webhooks->dispatch('order.rejected', $this->orderPayload($order), $credential->id);

        return OrderResult::failure($code);
    }

    private function newTxid(): string
    {
        return 'O'.strtoupper(Str::random(6)).'-'.strtoupper(Str::random(5)).'-'.strtoupper(Str::random(6));
    }

    private function adjustBalance(KrakenApiCredential $credential, string $asset, float $delta): void
    {
        $balance = KrakenBalance::firstOrCreate(
            ['kraken_api_credential_id' => $credential->id, 'asset' => strtoupper($asset)],
            ['amount' => 0],
        );
        $balance->update(['amount' => (float) $balance->amount + $delta]);
    }
}

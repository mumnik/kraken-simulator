<?php

namespace App\Http\Controllers;

use App\Kraken\AssetCodes;
use App\Kraken\Pricer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The unauthenticated surface — /0/public/*, same prefix real Kraken uses.
 * Only Ticker actually matters to this project's own KrakenRateSource
 * today; Time/SystemStatus exist because a lot of real Kraken client SDKs
 * probe one of those first to confirm connectivity before anything else,
 * and failing that silently would be a worse emulation than just
 * answering it.
 */
class PublicController extends Controller
{
    public function __construct(private readonly Pricer $pricer) {}

    public function ticker(Request $request): JsonResponse
    {
        $raw = (string) $request->query('pair', '');
        if ($raw === '') {
            return response()->json(['error' => ['EGeneral:Invalid arguments'], 'result' => []]);
        }

        $result = [];
        foreach (explode(',', $raw) as $one) {
            $pair = AssetCodes::parsePair($one);
            if ($pair === null || ! $this->pricer->exists($pair[0], $pair[1])) {
                // Real Kraken fails the whole call on one bad pair, not a
                // partial result for the ones it did recognize.
                return response()->json(['error' => ['EQuery:Unknown asset pair'], 'result' => []]);
            }
            [$base, $quote] = $pair;
            $key = AssetCodes::pairInternalKey($base, $quote);
            $result[$key] = $this->tickerEntry($base, $quote);
        }

        return response()->json(['error' => [], 'result' => $result]);
    }

    public function time(): JsonResponse
    {
        return response()->json(['error' => [], 'result' => ['unixtime' => now()->timestamp, 'rfc1123' => now()->toRfc7231String()]]);
    }

    public function systemStatus(): JsonResponse
    {
        return response()->json(['error' => [], 'result' => ['status' => 'online', 'timestamp' => now()->toIso8601String()]]);
    }

    /** @return array<string, mixed> */
    private function tickerEntry(string $base, string $quote): array
    {
        $last = $this->pricer->read($base, $quote);
        $spread = $last * 0.0005; // a plausible, made-up bid/ask spread — not real market depth
        $fmt = fn (float $v) => number_format($v, 10, '.', '');

        return [
            'a' => [$fmt($last + $spread), '0', $fmt(mt_rand(1, 50) / 10)],
            'b' => [$fmt($last - $spread), '0', $fmt(mt_rand(1, 50) / 10)],
            'c' => [$fmt($last), $fmt(mt_rand(1, 1000) / 1000)],
            'v' => [$fmt(mt_rand(100, 5000)), $fmt(mt_rand(1000, 50000))],
            'p' => [$fmt($last), $fmt($last)],
            't' => [mt_rand(10, 500), mt_rand(100, 5000)],
            'l' => [$fmt($last - $spread * 4), $fmt($last - $spread * 6)],
            'h' => [$fmt($last + $spread * 4), $fmt($last + $spread * 6)],
            'o' => $fmt($last - $spread * 2),
        ];
    }
}

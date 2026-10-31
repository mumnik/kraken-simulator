<?php

namespace App\Kraken;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place this simulator calls the REAL Kraken — Pricer uses this to
 * keep a 'live' pair's mid_price tracking the real market instead of
 * sitting on a fixed demo number forever. Deliberately swallows every
 * failure (network error, real Kraken down, unexpected shape) and returns
 * null rather than throwing — a real exchange being briefly unreachable
 * should never break this SIMULATOR's own availability, which is the
 * entire point of using it instead of the real thing. Pricer falls back
 * to the last known mid_price when this returns null.
 */
class LiveKrakenClient
{
    public function fetch(string $base, string $quote): ?float
    {
        $pair = AssetCodes::pairAltname($base, $quote);
        $url = (string) config('kraken.live_upstream_url');
        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout(5)->get($url, ['pair' => $pair]);
            if (! $response->ok()) {
                return null;
            }
            $json = $response->json();
            if (! empty($json['error'] ?? null)) {
                return null;
            }
            $entry = array_values($json['result'] ?? [])[0] ?? null;
            $price = $entry['c'][0] ?? null;

            return is_numeric($price) ? (float) $price : null;
        } catch (Throwable $e) {
            Log::warning('kraken-simulator: live price fetch failed', [
                'base' => $base, 'quote' => $quote, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

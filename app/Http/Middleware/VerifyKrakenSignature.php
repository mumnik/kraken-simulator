<?php

namespace App\Http\Middleware;

use App\Kraken\RequestSigner;
use App\Models\KrakenApiCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /0/private/* route goes through this. Real Kraken never uses an
 * HTTP error status for an API-level problem — invalid key, bad signature,
 * stale nonce all come back as a normal 200 with {"error": [...]}, same as
 * a real AddOrder rejection would. Replicating that here (rather than the
 * more RESTful 401/403 this would otherwise reach for) matters: code
 * written against this simulator that only checks response.error, never
 * the status code, is exactly how a client actually has to handle Kraken.
 */
class VerifyKrakenSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('API-Key');
        $apiSign = $request->header('API-Sign');
        if (! $apiKey || ! $apiSign) {
            return $this->reject('EAPI:Invalid key');
        }

        $credential = KrakenApiCredential::where('api_key', $apiKey)->first();
        if ($credential === null) {
            return $this->reject('EAPI:Invalid key');
        }

        $nonce = $request->input('nonce');
        if (! is_string($nonce) && ! is_numeric($nonce)) {
            return $this->reject('EAPI:Invalid nonce');
        }
        $nonce = (string) $nonce;

        // The raw, exact bytes of the POST body — not a re-serialization
        // of parsed params, which could legitimately differ in key order
        // or escaping from whatever the client actually signed. See
        // RequestSigner's own docblock.
        $rawBody = $request->getContent();
        $path = '/'.ltrim($request->path(), '/');

        $expected = RequestSigner::sign($path, $nonce, $rawBody, $credential->api_secret);
        if (! hash_equals($expected, (string) $apiSign)) {
            return $this->reject('EAPI:Invalid signature');
        }

        if ((int) $nonce <= $credential->last_nonce) {
            return $this->reject('EAPI:Invalid nonce');
        }
        $credential->update(['last_nonce' => (int) $nonce]);

        $request->attributes->set('kraken_credential', $credential);

        return $next($request);
    }

    private function reject(string $errorCode): Response
    {
        return response()->json(['error' => [$errorCode], 'result' => []]);
    }
}

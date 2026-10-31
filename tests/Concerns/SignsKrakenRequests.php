<?php

namespace Tests\Concerns;

use App\Kraken\RequestSigner;
use App\Models\KrakenApiCredential;
use App\Models\KrakenBalance;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Builds and sends a /0/private/* request the way a real Kraken client
 * actually has to: compute the signature over the exact raw, form-encoded
 * body, then send that same raw body — not Laravel's usual ->post($uri,
 * $array) test helper, which never puts anything in getContent() at all
 * (it populates the request's parameter bag directly), so
 * VerifyKrakenSignature's own raw-body read would see nothing to sign
 * against. This exercises the exact code path a real HTTP client takes.
 */
trait SignsKrakenRequests
{
    protected function makeCredential(array $balances = []): KrakenApiCredential
    {
        $credential = KrakenApiCredential::create([
            'label' => 'test',
            'api_key' => strtoupper(Str::random(56)),
            'api_secret' => base64_encode(random_bytes(64)),
        ]);
        foreach ($balances as $asset => $amount) {
            KrakenBalance::create(['kraken_api_credential_id' => $credential->id, 'asset' => $asset, 'amount' => $amount]);
        }

        return $credential;
    }

    protected function signedPost(KrakenApiCredential $credential, string $path, array $params = [], ?int $nonce = null, ?string $signOverride = null): TestResponse
    {
        $nonce ??= (int) (microtime(true) * 1_000_000) + random_int(0, 999);
        $body = $params + ['nonce' => $nonce];
        $postData = http_build_query($body);
        $sign = $signOverride ?? RequestSigner::sign($path, (string) $nonce, $postData, $credential->api_secret);

        // $body is passed BOTH as $parameters (so $request->input() works
        // reliably inside the test client, which doesn't always auto-parse
        // a raw form-urlencoded $content the way a real PHP SAPI would)
        // AND as the raw $content (so getContent() — what the middleware
        // actually signs against — is byte-for-byte what RequestSigner
        // signed above).
        return $this->call('POST', $path, $body, [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_API-Key' => $credential->api_key,
            'HTTP_API-Sign' => $sign,
        ], $postData);
    }
}

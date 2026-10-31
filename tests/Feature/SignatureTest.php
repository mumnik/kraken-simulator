<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsKrakenRequests;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    use RefreshDatabase, SignsKrakenRequests;

    public function test_a_correctly_signed_request_is_accepted(): void
    {
        $credential = $this->makeCredential(['EUR' => 100]);

        $res = $this->signedPost($credential, '/0/private/Balance');

        $res->assertOk()->assertJsonPath('error', []);
    }

    public function test_an_unknown_api_key_is_rejected_the_kraken_shaped_way(): void
    {
        $credential = $this->makeCredential();
        $credential->api_key = 'NOT-A-REAL-KEY';

        $res = $this->signedPost($credential, '/0/private/Balance');

        // 200, not 401/403 — see VerifyKrakenSignature's own docblock.
        $res->assertOk()->assertJsonPath('error.0', 'EAPI:Invalid key');
    }

    public function test_a_tampered_body_fails_signature_verification(): void
    {
        $credential = $this->makeCredential(['EUR' => 100]);
        // Signs one body, sends a different one — the classic "intercepted
        // and modified in transit" attack this scheme exists to catch.
        $nonce = (int) (microtime(true) * 1_000_000);
        $signedBody = http_build_query(['nonce' => $nonce, 'pair' => 'XBTEUR']);
        $sign = \App\Kraken\RequestSigner::sign('/0/private/AddOrder', (string) $nonce, $signedBody, $credential->api_secret);
        $actuallySent = ['nonce' => $nonce, 'pair' => 'ETHEUR'];
        $actuallySentBody = http_build_query($actuallySent);

        $res = $this->call('POST', '/0/private/AddOrder', $actuallySent, [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_API-Key' => $credential->api_key,
            'HTTP_API-Sign' => $sign,
        ], $actuallySentBody);

        $res->assertOk()->assertJsonPath('error.0', 'EAPI:Invalid signature');
    }

    public function test_nonce_must_strictly_increase(): void
    {
        $credential = $this->makeCredential(['EUR' => 100]);

        $this->signedPost($credential, '/0/private/Balance', nonce: 1000)->assertJsonPath('error', []);
        // Same nonce again — a real replay of the exact same signed request.
        $this->signedPost($credential, '/0/private/Balance', nonce: 1000)->assertJsonPath('error.0', 'EAPI:Invalid nonce');
        // Lower than the last accepted one.
        $this->signedPost($credential, '/0/private/Balance', nonce: 999)->assertJsonPath('error.0', 'EAPI:Invalid nonce');
        // Higher — accepted.
        $this->signedPost($credential, '/0/private/Balance', nonce: 1001)->assertJsonPath('error', []);
    }
}

<?php

namespace App\Kraken;

/**
 * Kraken's real private-endpoint signing algorithm, exactly as documented
 * at https://docs.kraken.com/api/docs/guides/spot-rest-auth —
 *
 *   api-sign = base64( HMAC-SHA512(
 *       key:     base64_decode(api_secret),
 *       message: url_path . SHA256(nonce . POST_data),
 *   ) )
 *
 * `url_path` is the request path only (e.g. "/0/private/AddOrder", no query
 * string). `nonce` is the raw nonce value's own string form — not
 * "nonce=<value>", just the digits. `POST_data` is the exact raw,
 * form-urlencoded request body, byte for byte — this is why the signature
 * verifier (VerifyKrakenSignature) reads the raw body rather than
 * re-encoding parsed params, which could reorder or re-escape them
 * differently than whatever the client actually sent.
 *
 * A real Kraken client SDK computes this to sign a request; this class
 * doubles as that reference implementation (see README) and as what
 * VerifyKrakenSignature recomputes to check an incoming request against.
 */
class RequestSigner
{
    public static function sign(string $urlPath, string $nonce, string $postData, string $apiSecretBase64): string
    {
        $secret = base64_decode($apiSecretBase64, strict: true);
        $message = $urlPath.hash('sha256', $nonce.$postData, binary: true);
        $hmac = hash_hmac('sha512', $message, $secret, binary: true);

        return base64_encode($hmac);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /internal/* admin surface (price/balance/settings overrides) —
 * the one trust boundary separate from Kraken's own real protocol
 * (/0/public, /0/private), which this deliberately never touches: a
 * misconfigured or leaked internal token must never be usable to forge a
 * signed Kraken request. Same hash_equals()-against-configured-secret,
 * empty-secret-skips-verification shape used throughout this project's
 * sibling services (see mumnik/ledger-sms-gateway's own VerifyBearerToken).
 */
class VerifyBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('kraken.internal_token', '');

        if ($expected !== '' && ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['error' => 'unauthorized', 'message' => 'invalid or missing bearer token'], 401);
        }

        return $next($request);
    }
}

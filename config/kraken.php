<?php

return [
    /*
     * Bearer token the /internal/* admin surface requires (price/balance/
     * settings overrides) — never used by the real Kraken-protocol routes
     * (/0/public, /0/private), which authenticate the real way instead.
     * Empty = skip verification, the same dev/sandbox convention this
     * project's sibling services use.
     */
    'internal_token' => env('KRAKEN_INTERNAL_TOKEN', ''),
];

<?php

return [
    /*
     * Bearer token the /internal/* admin surface requires (price/balance/
     * settings/webhook overrides) — never used by the real-protocol
     * routes (/0/public, /0/private), which authenticate the real way
     * instead. Empty = skip verification, the same dev/sandbox convention
     * this project's sibling services use.
     */
    'internal_token' => env('KRAKEN_INTERNAL_TOKEN', ''),

    /*
     * Where Pricer fetches a real price from for a 'live' pair — the real
     * Kraken's own public Ticker endpoint by default. Empty disables live
     * tracking entirely (every pair effectively behaves as 'manual', just
     * without ever flipping the stored source column) — useful for a
     * fully offline dev/test environment with no outbound network access.
     */
    'live_upstream_url' => env('KRAKEN_LIVE_UPSTREAM_URL', 'https://api.kraken.com/0/public/Ticker'),

    /*
     * How old a 'live' pair's mid_price can get before the next read()
     * refreshes it from the real Kraken. Short enough that the simulated
     * market stays recognizably "live", long enough that a burst of reads
     * doesn't hammer the real API.
     */
    'live_refresh_seconds' => env('KRAKEN_LIVE_REFRESH_SECONDS', 30),

    /*
     * How long WebhookDispatcher waits for an endpoint to respond before
     * giving up — short, since this blocks the request that triggered the
     * event (AddOrder, CancelOrder) until every subscribed endpoint has
     * been tried.
     */
    'webhook_timeout_seconds' => env('KRAKEN_WEBHOOK_TIMEOUT_SECONDS', 3),
];

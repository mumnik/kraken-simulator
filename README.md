# kraken-simulator

A protocol-accurate emulator of [Kraken](https://www.kraken.com)'s public and
private REST API — same paths, same request-signing algorithm, same
Kraken-shaped error codes — for developing and testing code that talks to
Kraken without touching the real exchange (and without a Kraken sandbox,
which doesn't exist for spot trading — only for Futures).

Built for [mumnik/ledger](https://github.com/mumnik/ledger)'s own
`KrakenRateSource` (crypto FX pricing) and any future hedging/execution
code that would place real orders against Kraken.

## What it emulates

- **`GET /0/public/Ticker`** — a real price, tracked live from the real
  Kraken and jittered on every read by a per-asset volatility (see "Live
  price tracking" and "Volatility" below). Also `GET /0/public/Time` and
  `GET /0/public/SystemStatus`, since a lot of real Kraken client SDKs
  probe one of those before anything else.
- **`POST /0/private/AddOrder`** — market and limit orders, spot and
  leveraged, with a real balance/margin check and a configurable chance of
  rejecting an otherwise-valid order for a transient reason (see "Failure
  injection" below).
- **`POST /0/private/Balance`**, **`TradeBalance`**, **`CancelOrder`**,
  **`OpenOrders`**, **`QueryOrders`** — the rest of what a basic
  order-placing client needs.
- **Real request signing** — `API-Key` / `API-Sign` headers, verified the
  same way Kraken's own servers do: `base64(HMAC-SHA512(path +
  SHA256(nonce + raw_post_body), base64_decode(secret)))`. A client
  written against the real Kraken REST API needs zero changes beyond its
  base URL to work against this.
- **Kraken's own error-response shape** — every API-level problem
  (unknown pair, bad signature, insufficient funds, ...) comes back as
  HTTP 200 with `{"error": ["ECategory:Reason"], "result": {}}`, never an
  HTTP 4xx/5xx — exactly how the real API behaves, and exactly what a
  client's own error handling has to be written against.

Assets known: **BTC, ETH, EUR, USD, USDT** (see `app/Kraken/AssetCodes.php`
to add another). Pairs seeded by default: BTC/EUR, BTC/USD, BTC/USDT,
ETH/EUR, ETH/USD, ETH/USDT, USDT/EUR, USDT/USD (see
`database/seeders/DatabaseSeeder.php`). A pair with neither leg seeded —
even a real one on the actual Kraken — has no rate here, same as
`KrakenVenue`'s own real-protocol behavior: no triangulation through a
third currency, only the exact pair or its reverse.

## What it deliberately does not emulate

- **Real margin accounting.** A leveraged order's required margin
  (`notional / leverage`) is checked and deducted straight from the quote
  asset's own balance — there's no running "margin used" ledger across
  open positions, no liquidation, no unwind. Good enough to exercise
  "insufficient margin" as a distinct failure from "insufficient funds" on
  a plain spot order; not a real margin engine.
- **A resting limit order's eventual fill.** A limit order away from the
  market stays `open` until `CancelOrder` — there's no background price
  walk that would later touch it and fill it as the (jittered) market
  moves through its price.
- **Order book depth, `AssetPairs`, WebSocket feeds**, or anything else
  real Kraken's API surface has that this project doesn't currently need.

## Running it

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

`migrate --seed` prints a demo API key/secret pair the first time it runs
(never again after — same "shown once" convention as every generated
credential elsewhere in this project). Save it.

## Pointing a real client at it

Anything that talks to `https://api.kraken.com` just needs its base URL
repointed here — the path shape, headers, and response bodies are the
same. `RequestSigner::sign()` (`app/Kraken/RequestSigner.php`) is also a
correct, minimal reference implementation of Kraken's own signing
algorithm if you're writing a client from scratch:

```php
$nonce = (string) (microtime(true) * 1_000_000);
$postData = http_build_query(['nonce' => $nonce, 'pair' => 'XBTEUR', ...]);
$sign = \App\Kraken\RequestSigner::sign('/0/private/AddOrder', $nonce, $postData, $apiSecret);

// POST $postData to {base_url}/0/private/AddOrder with headers:
//   API-Key: <api_key>
//   API-Sign: $sign
//   Content-Type: application/x-www-form-urlencoded
```

## Controlling it (`/internal/*`)

Not part of Kraken's real protocol — this simulator's own admin surface,
gated by a separate bearer token (`KRAKEN_INTERNAL_TOKEN`, empty = no
auth, same dev/sandbox convention as every other internal trust boundary
in this project's ecosystem). Never shares a secret with the signed
`/0/private/*` routes.

| | |
|---|---|
| `POST /internal/prices` | `{base, quote, mid_price}` — pin a pair's mid price and switch it to `manual` (see "Live price tracking"). Every `Ticker` read and market-order fill wobbles around whatever this is currently set to. |
| `POST /internal/prices/live` | `{base, quote}` — switch a pair back to tracking the real Kraken; the next read refreshes it. |
| `GET /internal/prices` | List every seeded pair's current mid price, `source` (`live`/`manual`), and when it was last synced. |
| `POST /internal/balances` | `{api_key, asset, amount}` — set a credential's balance for one asset directly (top up a demo account, or force an insufficient-funds scenario). |
| `GET\|POST /internal/settings` | `{random_fail_probability}` — read or update the failure-injection knob below. |
| `POST /internal/asset-volatility` | `{asset, volatility_pct}` — set how much one asset wobbles (see "Volatility" below). |
| `GET /internal/asset-volatility` | List every asset's configured volatility. |
| `GET /internal/credentials` | List every seeded API key (not secrets — those are shown once at seed time and never again) with current balances. |
| `GET /internal/orders` | Last 100 orders, filled and rejected alike, with `reject_reason` for the rejected ones. |
| `POST /internal/webhooks` | `{url, events[], api_key?}` — register a webhook endpoint (see "Webhooks" below). Returns `{id, secret}` — the secret is shown once. |
| `GET /internal/webhooks` | List registered endpoints (never their secrets). |
| `DELETE /internal/webhooks/{id}` | Remove an endpoint. |
| `GET /internal/webhooks/deliveries` | Last 100 delivery attempts, with each one's HTTP status or error. |

### Live price tracking

Every seeded pair starts as `source: 'live'`: whenever a `Ticker` read (or
a market-order fill) finds its stored `mid_price` older than
`KRAKEN_LIVE_REFRESH_SECONDS` (default 30s), it's refreshed from the real
Kraken's own public `Ticker` endpoint (`KRAKEN_LIVE_UPSTREAM_URL`) before
the volatility jitter is applied — so by default this simulator's
"market" genuinely tracks the real one, not a frozen demo number. A
failed fetch (network hiccup, real Kraken briefly down) falls back to the
last known price rather than erroring — this simulator's own availability
should never depend on the real exchange's.

`POST /internal/prices` pins a pair to an exact value and switches it to
`manual` — a live refresh never touches a manual pair, so a scenario
deliberately engineered for a test (a specific price, a specific R1-vs-R2
drift) holds until `POST /internal/prices/live` asks for it back. Set
`KRAKEN_LIVE_UPSTREAM_URL=` (empty) to disable live tracking globally —
every pair then just stays on whatever it was last set to, with no
outbound network call ever made (this is what `phpunit.xml` does for the
test suite).

### Volatility

Configured **per asset**, not per pair (`POST /internal/asset-volatility`,
default seeded: BTC 1.5%, ETH 2.0%, EUR/USD/USDT 0.05%) — a real market's
wobble is a property of each side (BTC moves a lot regardless of what
it's quoted against; a stablecoin barely moves at all), so this scales
with however many assets exist rather than needing a number per pair. A
pair's own wobble combines both sides' volatility in quadrature
(`sqrt(a² + b²)`, the standard way to combine two independent sources of
noise). Every `Ticker` read and market-order fill is jittered
independently off the current `mid_price` and never persists, so repeated
reads wander around it, never drift away on their own. Set an asset to
`0` for deterministic test assertions; turn it up to make R1-vs-R2 drift
rejection easy to trigger on demand (exactly what `mumnik/ledger`'s own
`FxService::confirm()` checks for), or set a currently-unconfigured
asset's volatility to pin down a new pair's behavior explicitly instead
of relying on the default fallback (0.5%).

### Failure injection

`random_fail_probability` (default `0`, i.e. never) is the chance an
`AddOrder` that passed every real check (known pair, valid params, enough
balance/margin) gets rejected anyway, with one of:

- `EService:Unavailable`
- `EOrder:Cannot open position`
- `EAPI:Rate limit exceeded`
- `EGeneral:Temporary lockout`

Real exchanges reject perfectly valid orders sometimes — rate limits,
momentary liquidity gaps, risk-engine hiccups — and code that places real
money on an exchange needs to handle that, not just the deterministic
"you didn't have the funds" case. Set this above `0` to exercise that
retry/error-handling path on demand; every attempt (filled, rejected for
a real reason, or rejected by this) is logged to `GET /internal/orders`
either way.

### Webhooks

Not part of Kraken's real protocol at all — real Kraken has no webhooks,
only REST polling and a WebSocket feed. This is this simulator's own
addition, for code that wants to react to an order filling or failing
without polling `QueryOrders` in a loop.

```bash
curl -X POST http://localhost:8000/internal/webhooks \
  -H 'Content-Type: application/json' \
  -d '{"url": "https://your-app.test/hooks/kraken", "events": ["order.filled", "order.rejected"]}'
# => {"data": {"id": 1, "secret": "..."}}  — the secret is shown once, save it
```

Omit `api_key` to fire for every credential's events, or pass one to
scope the subscription to a single simulated account. Events: `order.filled`,
`order.rejected`, `order.canceled`. Each delivery is a `POST` to your `url`
with:

```json
{"event": "order.filled", "data": {"txid": "...", "pair": "BTC/EUR", "...": "..."}, "timestamp": "2026-10-31T12:00:00+00:00"}
```

signed the same way this simulator verifies its own inbound requests —
over the exact raw JSON body, via `X-Kraken-Simulator-Signature:
hmac_sha256(body, secret)`. Verify it with `hash_equals(expected,
$receivedSignature)`.

Delivery is synchronous and inline with the request that triggered it
(`AddOrder`/`CancelOrder`), with a short timeout
(`KRAKEN_WEBHOOK_TIMEOUT_SECONDS`, default 3s) — a slow or dead endpoint
never fails the order itself, it's just recorded as a failed delivery.
`GET /internal/webhooks/deliveries` is where to check whether something
actually fired, not the API response.

## Tests

```bash
php artisan test
```

`tests/Concerns/SignsKrakenRequests.php` is the one non-obvious piece —
it builds and sends a `/0/private/*` request the way a real Kraken client
actually has to (signature computed over, and sent as, the exact raw
form-encoded body), not Laravel's usual `->post($uri, $array)` test
helper, which never populates `getContent()` at all.

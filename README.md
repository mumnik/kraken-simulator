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

- **`GET /0/public/Ticker`** — live(-ish) price per pair, jittered on every
  read (see "Volatility" below). Also `GET /0/public/Time` and
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
to add another). Pairs seeded by default: BTC/EUR, BTC/USD, ETH/EUR,
ETH/USD, USDT/EUR, USDT/USD (see `database/seeders/DatabaseSeeder.php`).

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
| `POST /internal/prices` | `{base, quote, mid_price}` — set a pair's mid price. Every `Ticker` read and market-order fill wobbles around whatever this is currently set to. |
| `GET /internal/prices` | List every seeded pair's current mid price. |
| `POST /internal/balances` | `{api_key, asset, amount}` — set a credential's balance for one asset directly (top up a demo account, or force an insufficient-funds scenario). |
| `GET\|POST /internal/settings` | `{volatility_pct, random_fail_probability}` — read or update the two global knobs below. |
| `GET /internal/credentials` | List every seeded API key (not secrets — those are shown once at seed time and never again) with current balances. |
| `GET /internal/orders` | Last 100 orders, filled and rejected alike, with `reject_reason` for the rejected ones. |

### Volatility

`volatility_pct` (default `0.15`, i.e. ±0.15%) is how much a `Ticker` read
or a market-order fill wobbles off the stored `mid_price` — every read is
jittered independently and never persists, so repeated reads wander
around the set price, never drift away from it. Set it to `0` for
deterministic test assertions; turn it up to deliberately engineer an
R1-vs-R2 rate-drift rejection in code that locks a rate and re-checks it
later (exactly what `mumnik/ledger`'s own `FxService::confirm()` does).

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

## Tests

```bash
php artisan test
```

`tests/Concerns/SignsKrakenRequests.php` is the one non-obvious piece —
it builds and sends a `/0/private/*` request the way a real Kraken client
actually has to (signature computed over, and sent as, the exact raw
form-encoded body), not Laravel's usual `->post($uri, $array)` test
helper, which never populates `getContent()` at all.

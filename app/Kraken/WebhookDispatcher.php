<?php

namespace App\Kraken;

use App\Models\KrakenWebhookDelivery;
use App\Models\KrakenWebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Not part of Kraken's real protocol — real Kraken has no webhooks, only
 * REST polling and a WebSocket feed. This exists so code reacting to an
 * order filling/failing doesn't have to poll QueryOrders in a loop.
 *
 * Delivery is synchronous, inline in the request that triggered the event
 * (OrderEngine calls this directly, not through a queue — this service
 * has no queue worker, same minimal-infra shape as every sibling service
 * in this project's own ecosystem). A short timeout
 * (config('kraken.webhook_timeout_seconds')) and a swallowed exception
 * keep a slow or dead endpoint from ever making the actual AddOrder/
 * CancelOrder call that triggered it fail — every attempt is recorded to
 * kraken_webhook_deliveries regardless of outcome, so GET /internal/
 * webhooks/deliveries is where to look, not the API response.
 */
class WebhookDispatcher
{
    public function dispatch(string $event, array $data, ?int $credentialId = null): void
    {
        $endpoints = KrakenWebhookEndpoint::where('active', true)
            ->where(fn ($q) => $q->whereNull('kraken_api_credential_id')->orWhere('kraken_api_credential_id', $credentialId))
            ->get()
            ->filter(fn (KrakenWebhookEndpoint $e) => $e->subscribesTo($event));

        foreach ($endpoints as $endpoint) {
            $this->send($endpoint, $event, $data);
        }
    }

    private function send(KrakenWebhookEndpoint $endpoint, string $event, array $data): void
    {
        $payload = ['event' => $event, 'data' => $data, 'timestamp' => now()->toIso8601String()];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        $delivery = KrakenWebhookDelivery::create([
            'kraken_webhook_endpoint_id' => $endpoint->id,
            'event' => $event,
            'payload' => $payload,
        ]);

        try {
            $timeout = (int) config('kraken.webhook_timeout_seconds', 3);
            $response = Http::timeout($timeout)
                ->withBody($body, 'application/json')
                ->withHeaders(['X-Kraken-Simulator-Signature' => $signature])
                ->post($endpoint->url);

            $delivery->update(['response_status' => $response->status()]);
        } catch (Throwable $e) {
            $delivery->update(['error' => $e->getMessage()]);
            Log::warning('kraken-simulator: webhook delivery failed', [
                'endpoint_id' => $endpoint->id, 'event' => $event, 'error' => $e->getMessage(),
            ]);
        }
    }
}

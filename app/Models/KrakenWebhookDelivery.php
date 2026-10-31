<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KrakenWebhookDelivery extends Model
{
    protected $fillable = ['kraken_webhook_endpoint_id', 'event', 'payload', 'response_status', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(KrakenWebhookEndpoint::class, 'kraken_webhook_endpoint_id');
    }
}

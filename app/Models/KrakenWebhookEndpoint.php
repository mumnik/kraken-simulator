<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KrakenWebhookEndpoint extends Model
{
    protected $fillable = ['kraken_api_credential_id', 'url', 'secret', 'events', 'active'];

    protected function casts(): array
    {
        return ['events' => 'array', 'active' => 'boolean'];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(KrakenApiCredential::class, 'kraken_api_credential_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(KrakenWebhookDelivery::class);
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events, true);
    }
}

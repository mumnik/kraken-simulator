<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KrakenOrder extends Model
{
    protected $fillable = [
        'kraken_api_credential_id', 'txid', 'pair', 'type', 'ordertype',
        'volume', 'price', 'leverage', 'status', 'reject_reason', 'opened_at', 'closes_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'volume' => 'decimal:10',
            'price' => 'decimal:10',
            'leverage' => 'decimal:2',
            'opened_at' => 'datetime',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** An order whose fill is deliberately deferred (see OrderEngine::addOrder()) and due now. */
    public function isDueToFinalize(): bool
    {
        return $this->status === 'open' && $this->closes_at !== null && $this->closes_at->isPast();
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(KrakenApiCredential::class, 'kraken_api_credential_id');
    }
}

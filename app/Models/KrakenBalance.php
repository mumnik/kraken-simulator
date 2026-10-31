<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KrakenBalance extends Model
{
    protected $fillable = ['kraken_api_credential_id', 'asset', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:10'];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(KrakenApiCredential::class, 'kraken_api_credential_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KrakenApiCredential extends Model
{
    use HasFactory;

    protected $fillable = ['label', 'api_key', 'api_secret', 'last_nonce'];

    protected function casts(): array
    {
        return ['last_nonce' => 'integer'];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(KrakenBalance::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(KrakenOrder::class);
    }

    public function balanceOf(string $asset): float
    {
        return (float) ($this->balances()->where('asset', strtoupper($asset))->value('amount') ?? 0);
    }
}

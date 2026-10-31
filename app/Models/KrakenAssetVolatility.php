<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KrakenAssetVolatility extends Model
{
    // Laravel would otherwise guess "kraken_asset_volatilities" (English
    // pluralization of the class name) — the migration uses the singular
    // table name instead.
    protected $table = 'kraken_asset_volatility';

    protected $primaryKey = 'asset';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['asset', 'volatility_pct'];

    protected function casts(): array
    {
        return ['volatility_pct' => 'decimal:4'];
    }
}

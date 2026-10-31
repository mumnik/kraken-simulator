<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KrakenPrice extends Model
{
    protected $fillable = ['base', 'quote', 'mid_price', 'source', 'live_synced_at'];

    protected function casts(): array
    {
        return [
            'mid_price' => 'decimal:10',
            'live_synced_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->source === 'live';
    }
}

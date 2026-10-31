<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KrakenPrice extends Model
{
    protected $fillable = ['base', 'quote', 'mid_price'];

    protected function casts(): array
    {
        return ['mid_price' => 'decimal:10'];
    }
}

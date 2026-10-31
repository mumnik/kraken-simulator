<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KrakenSetting extends Model
{
    protected $fillable = ['volatility_pct', 'random_fail_probability'];

    protected function casts(): array
    {
        return [
            'volatility_pct' => 'decimal:4',
            'random_fail_probability' => 'decimal:4',
        ];
    }

    /** The one row (id=1) — seeded by the create-table migration itself. */
    public static function current(): self
    {
        return self::findOrFail(1);
    }
}

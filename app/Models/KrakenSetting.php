<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KrakenSetting extends Model
{
    protected $fillable = ['random_fail_probability', 'fill_delay_seconds'];

    protected function casts(): array
    {
        return ['random_fail_probability' => 'decimal:4', 'fill_delay_seconds' => 'integer'];
    }

    /** The one row (id=1) — seeded by the create-table migration itself. */
    public static function current(): self
    {
        return self::findOrFail(1);
    }
}

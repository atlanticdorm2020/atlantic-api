<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomRate extends Model
{
    protected $fillable = [
        'rate_id',
        'hours',
        'status',
        'type',
        'location_id',
        'rate_per_hour',
    ];

    protected function casts(): array
    {
        return [
            'rate_id' => 'integer',
            'hours' => 'integer',
            'status' => 'integer',
            'type' => 'integer',
            'location_id' => 'integer',
            'rate_per_hour' => 'float',
        ];
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}

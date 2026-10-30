<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchHistory extends Model
{
    protected $table = 'switch_history';

    protected $fillable = [
        'transaction_id',
        'day_remain',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'day_remain' => 'integer',
            'rate' => 'float',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

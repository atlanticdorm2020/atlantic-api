<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionDetail extends Model
{
    protected $fillable = [
        'transaction_id',
        'location_id',
        'status',
        'extend_days',
        'penalty_days',
        'extend_date',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'location_id' => 'integer',
            'status' => 'integer',
            'extend_days' => 'integer',
            'penalty_days' => 'integer',
            'extend_date' => 'datetime',
            'amount' => 'float',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function bedHistories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BedHistory::class);
    }
}

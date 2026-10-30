<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $fillable = [
        'unique_id',
        'bed_id',
        'customer_id',
        'location_id',
        'no_day',
        'extend_day',
        'remaining_day',
        'consumed_day',
        'penalty_day',
        'rates',
        'amount',
        'status',
        'isContinue',
        'penalty',
        'final_amount',
        'login',
        'logout',
    ];

    protected function casts(): array
    {
        return [
            'bed_id' => 'integer',
            'customer_id' => 'integer',
            'location_id' => 'integer',
            'no_day' => 'integer',
            'extend_day' => 'integer',
            'remaining_day' => 'integer',
            'consumed_day' => 'integer',
            'penalty_day' => 'integer',
            'rates' => 'float',
            'amount' => 'float',
            'isContinue' => 'integer',
            'penalty' => 'float',
            'final_amount' => 'float',
            'login' => 'datetime',
            'logout' => 'datetime',
        ];
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(TransactionDetail::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(History::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }

    public function switchHistories(): HasMany
    {
        return $this->hasMany(SwitchHistory::class);
    }

    public function bedHistories(): HasMany
    {
        return $this->hasMany(BedHistory::class);
    }
}

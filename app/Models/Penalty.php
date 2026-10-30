<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Penalty extends Model
{
    protected $primaryKey = 'penalty_id';

    protected $fillable = [
        'transaction_id',
        'customer_id',
        'amount',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'customer_id' => 'integer',
            'amount' => 'float',
            'user_id' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

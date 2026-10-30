<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionWalkinDetail extends Model
{
    protected $table = 'transaction_walkin_details';

    protected $fillable = [
        'transaction_id',
        'location_id',
        'status',
        'hours',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'location_id' => 'integer',
            'status' => 'integer',
            'hours' => 'integer',
            'amount' => 'float',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(TransactionWalkin::class, 'transaction_id');
    }
}

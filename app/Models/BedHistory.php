<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BedHistory extends Model
{
    protected $table = 'bed_history';

    protected $fillable = [
        'transaction_id',
        'transaction_detail_id',
        'customer_id',
        'bed_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'transaction_detail_id' => 'integer',
            'customer_id' => 'integer',
            'bed_id' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function transactionDetail(): BelongsTo
    {
        return $this->belongsTo(TransactionDetail::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }
}

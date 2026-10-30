<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionWalkin extends Model
{
    protected $table = 'transaction_walkin';

    protected $fillable = [
        'unique_id',
        'location_id',
        'customer_id',
        'room_id',
        'bed_id',
        'transaction_type',
        'hours',
        'extend_hours',
        'rates',
        'login',
        'time_out',
        'logout',
        'status',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'customer_id' => 'integer',
            'room_id' => 'integer',
            'bed_id' => 'integer',
            'transaction_type' => 'integer',
            'hours' => 'integer',
            'extend_hours' => 'integer',
            'rates' => 'float',
            'login' => 'datetime',
            'time_out' => 'datetime',
            'logout' => 'datetime',
            'status' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerWalkin::class, 'customer_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(TransactionWalkinDetail::class, 'transaction_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    public const TYPE_TRANSACTION = 'transaction';

    public const TYPE_WALKIN = 'walkin';

    public const STATUS_PENDING = 0;

    public const STATUS_APPROVED = 1;

    public const STATUS_REJECTED = 2;

    public const STATUS_CANCELLED = 3;

    protected $fillable = [
        'customer_id',
        'user_id',
        'location_id',
        'type',
        'room_id',
        'custom_rate_id',
        'transaction_type',
        'no_day',
        'hours',
        'estimated_amount',
        'preferred_check_in',
        'note',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'user_id' => 'integer',
            'location_id' => 'integer',
            'room_id' => 'integer',
            'custom_rate_id' => 'integer',
            'transaction_type' => 'integer',
            'no_day' => 'integer',
            'hours' => 'integer',
            'estimated_amount' => 'float',
            'preferred_check_in' => 'datetime',
            'status' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function customRate(): BelongsTo
    {
        return $this->belongsTo(CustomRate::class);
    }
}

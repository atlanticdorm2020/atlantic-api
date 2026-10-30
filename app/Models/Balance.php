<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Balance extends Model
{
    protected $table = 'balance';

    protected $primaryKey = 'balance_id';

    protected $fillable = [
        'customer_id',
        'location_id',
        'room_id',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'location_id' => 'integer',
            'room_id' => 'integer',
            'balance' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}

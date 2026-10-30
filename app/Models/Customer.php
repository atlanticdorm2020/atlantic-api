<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'location_id',
        'first_name',
        'last_name',
        'middle_name',
        'sirb_no',
        'mobile_no',
        'permanent_address',
        'rank',
        'agency',
        'icoe_name',
        'icoe_relation',
        'icoe_contact',
        'note',
        'status',
        'balance',
        'penalty_amount',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'status' => 'integer',
            'balance' => 'integer',
            'penalty_amount' => 'float',
            'user_id' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(CustomerFile::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    public function bedHistories(): HasMany
    {
        return $this->hasMany(BedHistory::class);
    }
}

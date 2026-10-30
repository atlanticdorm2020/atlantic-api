<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name',
        'location_id',
        'rate_id',
        'is_bedspace',
        'status',
        'ordered',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'rate_id' => 'integer',
            'is_bedspace' => 'integer',
            'status' => 'integer',
            'ordered' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    public function transactionWalkins(): HasMany
    {
        return $this->hasMany(TransactionWalkin::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name',
        'address',
        'status',
        'rate_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'rate_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function transactionDetails(): HasMany
    {
        return $this->hasMany(TransactionDetail::class);
    }

    public function customRates(): HasMany
    {
        return $this->hasMany(CustomRate::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    public function transactionWalkins(): HasMany
    {
        return $this->hasMany(TransactionWalkin::class);
    }

    public function transactionWalkinDetails(): HasMany
    {
        return $this->hasMany(TransactionWalkinDetail::class);
    }
}

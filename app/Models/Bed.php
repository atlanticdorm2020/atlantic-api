<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bed extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name',
        'status',
        'sort',
        'room_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'sort' => 'integer',
            'room_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function bedHistories(): HasMany
    {
        return $this->hasMany(BedHistory::class);
    }

    public function transactionWalkins(): HasMany
    {
        return $this->hasMany(TransactionWalkin::class);
    }
}

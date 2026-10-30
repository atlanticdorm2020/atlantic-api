<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerWalkin extends Model
{
    protected $table = 'customer_walkin';

    protected $fillable = [
        'name',
        'id_presented',
        'status',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TransactionWalkin::class, 'customer_id');
    }
}

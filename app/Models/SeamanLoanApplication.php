<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeamanLoanApplication extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONTACTED,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'full_name',
        'address',
        'birthday',
        'phone',
        'email',
        'position_rank',
        'basic_salary',
        'departure_date',
        'agency_name',
        'loanable_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'departure_date' => 'date',
            'basic_salary' => 'decimal:2',
            'loanable_amount' => 'decimal:2',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SeamanLoanPayment::class)->orderByDesc('paid_at')->orderByDesc('id');
    }
}

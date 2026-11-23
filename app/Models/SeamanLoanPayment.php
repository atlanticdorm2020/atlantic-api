<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeamanLoanPayment extends Model
{
    public const METHOD_GCASH = 'gcash';

    public const METHOD_BANK = 'bank';

    public const METHOD_CHEQUE = 'cheque';

    public const METHODS = [
        self::METHOD_GCASH,
        self::METHOD_BANK,
        self::METHOD_CHEQUE,
    ];

    public const METHOD_LABELS = [
        self::METHOD_GCASH => 'GCash',
        self::METHOD_BANK => 'Bank transfer',
        self::METHOD_CHEQUE => 'Cheque',
    ];

    protected $fillable = [
        'seaman_loan_application_id',
        'amount',
        'method',
        'reference',
        'paid_at',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(SeamanLoanApplication::class, 'seaman_loan_application_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

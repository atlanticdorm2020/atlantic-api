<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceptionistDutyHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'duty_id',
        'changed_by',
        'changes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'duty_id' => 'integer',
            'changed_by' => 'integer',
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function duty(): BelongsTo
    {
        return $this->belongsTo(ReceptionistDuty::class, 'duty_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

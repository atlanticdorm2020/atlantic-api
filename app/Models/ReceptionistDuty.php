<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ReceptionistDuty extends Model
{
    public const TIMEZONE = 'Asia/Manila';

    public const MAX_DAYS = 30;

    protected $fillable = [
        'user_id',
        'location_id',
        'duty_date',
        'end_date',
        'start_time',
        'cutoff_time',
        'overnight',
        'timezone',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'location_id' => 'integer',
            'duty_date' => 'date',
            'end_date' => 'date',
            'overnight' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function receptionist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ReceptionistDutyHistory::class, 'duty_id');
    }

    public function timezoneName(): string
    {
        return $this->timezone ?: self::TIMEZONE;
    }

    public function dateFrom(): Carbon
    {
        return ($this->duty_date ?: Carbon::today($this->timezoneName()))->copy()->startOfDay();
    }

    public function dateTo(): Carbon
    {
        return ($this->end_date ?: $this->duty_date ?: Carbon::today($this->timezoneName()))->copy()->startOfDay();
    }

    public function dayCount(): int
    {
        return $this->dateFrom()->diffInDays($this->dateTo()) + 1;
    }

    public function isOvernight(): bool
    {
        if ($this->overnight) {
            return true;
        }

        return substr((string) $this->cutoff_time, 0, 5) <= substr((string) $this->start_time, 0, 5);
    }

    public function startsAt(?string $date = null): Carbon
    {
        $date = $date ?: $this->dateFrom()->toDateString();

        return Carbon::parse($date.' '.$this->start_time, $this->timezoneName());
    }

    public function cutsOffAt(?string $date = null): Carbon
    {
        $date = $date ?: $this->dateTo()->toDateString();
        $cutoff = Carbon::parse($date.' '.$this->cutoff_time, $this->timezoneName());

        if ($this->isOvernight()) {
            $cutoff->addDay();
        }

        return $cutoff;
    }

    public function periodStartsAt(): Carbon
    {
        return $this->startsAt($this->dateFrom()->toDateString());
    }

    public function periodCutsOffAt(): Carbon
    {
        return $this->cutsOffAt($this->dateTo()->toDateString());
    }

    public function windowStatus(?Carbon $now = null): string
    {
        $now = ($now ?: Carbon::now($this->timezoneName()))->timezone($this->timezoneName());
        $start = $this->periodStartsAt();
        $cutoff = $this->periodCutsOffAt();

        if ($now->lt($start)) {
            return 'scheduled';
        }

        if ($now->lte($cutoff)) {
            return 'active';
        }

        return 'completed';
    }

    public function containsCheckIn(?Carbon $checkedIn): bool
    {
        if (! $checkedIn) {
            return false;
        }

        $checkedIn = $checkedIn->copy()->timezone($this->timezoneName());
        $from = $this->dateFrom()->toDateString();
        $to = $this->dateTo()->toDateString();
        $candidates = [$checkedIn->toDateString()];

        if ($this->isOvernight()) {
            $candidates[] = $checkedIn->copy()->subDay()->toDateString();
        }

        foreach (array_unique($candidates) as $date) {
            if ($date < $from || $date > $to) {
                continue;
            }

            if ($checkedIn->betweenIncluded($this->startsAt($date), $this->cutsOffAt($date))) {
                return true;
            }
        }

        return false;
    }

    public function scopeOverlapping(Builder $query, string $start, string $end): Builder
    {
        return $query
            ->whereDate('duty_date', '<=', $end)
            ->whereRaw('COALESCE(end_date, duty_date) >= ?', [$start]);
    }
}

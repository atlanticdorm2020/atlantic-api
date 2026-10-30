<?php

namespace App\Services;

class TransactionPricing
{
    private const MIN_DAYS_WITHOUT_BALANCE = 5;

    private const MONTHLY_DAYS = 30;

    private const MONTHLY_DISCOUNT = 500;

    public function minDaysWithoutBalance(): int
    {
        return self::MIN_DAYS_WITHOUT_BALANCE;
    }

    /**
     * @return array{no_day: int, rate: float, amount: float, is_continue: int, upgrade_penalty: float}
     */
    public function resolve(
        int $requestedDays,
        float $currentRate,
        int $prepaidBalance,
        ?float $previousRate,
    ): array {
        $isContinue = $prepaidBalance > 0 ? 1 : 0;
        $noDay = $prepaidBalance > 0 ? $prepaidBalance : $requestedDays;
        $upgradePenalty = 0.0;

        if ($isContinue) {
            if ($previousRate !== null && $currentRate > $previousRate) {
                $upgradePenalty = ($prepaidBalance * $currentRate) - ($prepaidBalance * $previousRate);
            }

            return [
                'no_day' => $noDay,
                'rate' => $currentRate,
                'amount' => 0.0,
                'is_continue' => 1,
                'upgrade_penalty' => round(max(0, $upgradePenalty), 2),
            ];
        }

        $pricing = $this->calculateNewStayPricing($noDay, $currentRate);

        return [
            'no_day' => $pricing['no_day'],
            'rate' => $pricing['rate'],
            'amount' => $pricing['amount'],
            'is_continue' => 0,
            'upgrade_penalty' => 0.0,
        ];
    }

    public function monthlyAmount(float $rate): float
    {
        return round(max(0, ($rate * self::MONTHLY_DAYS) - self::MONTHLY_DISCOUNT), 2);
    }

    public function extensionAmount(int $addedDays, float $rate): float
    {
        if ($addedDays <= 0) {
            return 0.0;
        }

        if ($addedDays >= self::MONTHLY_DAYS) {
            return $this->monthlyAmount($rate);
        }

        return round($rate * $addedDays, 2);
    }

    /**
     * @return array{no_day: int, rate: float, amount: float}
     */
    private function calculateNewStayPricing(int $noDay, float $rate): array
    {
        if ($noDay >= self::MONTHLY_DAYS) {
            return [
                'no_day' => self::MONTHLY_DAYS,
                'rate' => $rate,
                'amount' => $this->monthlyAmount($rate),
            ];
        }

        return [
            'no_day' => $noDay,
            'rate' => $rate,
            'amount' => round($rate * $noDay, 2),
        ];
    }
}

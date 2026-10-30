<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Carbon;

class TransactionDayCalculator
{
    /**
     * Legacy checkout/logout consumed day rules.
     */
    public function consumedDaysForCheckout(Transaction $transaction): int
    {
        if (! $transaction->login) {
            return 0;
        }

        $timezone = 'Asia/Manila';
        $loginAt = Carbon::parse($transaction->login)->timezone($timezone);
        $now = now($timezone);

        $loginNoon = $loginAt->copy()->setTime(12, 0, 0);
        $logoutNow = $now->copy()->startOfHour();

        $loginHour = (int) $loginAt->format('H');
        $currentHour = (int) $now->format('H');

        $datediff = $logoutNow->getTimestamp() - $loginNoon->getTimestamp();
        $consumeDay = (int) ($datediff / (60 * 60 * 24));

        if ($consumeDay > 0) {
            $consumeDay += 1;

            if ($loginHour < 12 && $currentHour >= 12) {
                $consumeDay += 1;
            }

            if ($loginHour < 12 && $currentHour < 12) {
                $consumeDay += 1;
            }
        } elseif ($loginHour < 12 && $currentHour >= 12) {
            $consumeDay += 2;
        } else {
            $consumeDay += 1;

            if ($loginHour < 12 && (int) $loginAt->format('d') < (int) $now->format('d')) {
                $consumeDay += 1;
            }
        }

        return max(0, $consumeDay);
    }

    /**
     * Legacy switch-bed consumed day rules.
     */
    public function consumedDaysForSwitch(Transaction $transaction): int
    {
        if (! $transaction->login) {
            return 0;
        }

        $timezone = 'Asia/Manila';
        $loginAt = Carbon::parse($transaction->login)->timezone($timezone);
        $now = now($timezone);

        $loginNoon = $loginAt->copy()->setTime(12, 0, 0);
        $logoutNow = $now->copy()->startOfHour();

        $loginHour = (int) $loginAt->format('H');
        $currentHour = (int) $now->format('H');

        $datediff = $logoutNow->getTimestamp() - $loginNoon->getTimestamp();
        $consumeDay = (int) ($datediff / (60 * 60 * 24));

        if ($consumeDay > 0) {
            $consumeDay += 1;

            if ($loginHour < 12 && $currentHour >= 12) {
                $consumeDay += 1;
            }
        } elseif ($loginHour < 12 && $currentHour >= 12) {
            $consumeDay += 2;
        } else {
            $consumeDay += 1;

            if ((int) $loginAt->format('d') < (int) $now->format('d')) {
                $consumeDay += 1;
            }
        }

        return max(0, $consumeDay);
    }
}

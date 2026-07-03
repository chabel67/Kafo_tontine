<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Exceptions\PinInvalidException;
use App\Modules\Identity\Domain\Exceptions\PinLockedException;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class PinService
{
    public function set(User $user, string $rawPin): void
    {
        if (! $this->isValidPin($rawPin)) {
            throw new \InvalidArgumentException('PIN must be 6 digits and cannot be a simple sequence or all same digits.');
        }

        $user->update(['pin_hash' => Hash::make($rawPin)]);
    }

    public function verify(User $user, string $rawPin): void
    {
        $lockKey    = "pin:lock:{$user->id}";
        $failKey    = "pin:fails:{$user->id}";
        if (! $user->pin_hash) {
            throw new PinInvalidException();
        }

        $lockedUtil = Cache::get($lockKey);

        if ($lockedUtil) {
            $remaining = max(0, $lockedUtil - now()->timestamp);
            throw new PinLockedException($remaining);
        }

        $matches = Hash::check($rawPin, $user->pin_hash ?? '');
        if (! $matches) {
            $fails = (int) Cache::increment($failKey);
            Cache::put($failKey, $fails, 3600);

            if ($fails >= 10) {
                Cache::put($lockKey, now()->addHours(24)->timestamp, 86400);
                throw new PinLockedException(86400);
            }

            if ($fails >= 6) {
                Cache::put($lockKey, now()->addMinutes(15)->timestamp, 900);
                throw new PinLockedException(900);
            }

            if ($fails >= 3) {
                Cache::put($lockKey, now()->addMinute()->timestamp, 60);
                throw new PinLockedException(60);
            }

            throw new PinInvalidException();
        }

        Cache::forget($failKey);
    }

    private function isValidPin(string $pin): bool
    {
        if (! preg_match('/^\d{6}$/', $pin)) {
            return false;
        }

        // Reject all-same digits (000000, 111111…)
        if (preg_match('/^(\d)\1{5}$/', $pin)) {
            return false;
        }

        // Reject ascending/descending sequences
        $ascending  = '0123456789';
        $descending = '9876543210';
        if (str_contains($ascending, $pin) || str_contains($descending, $pin)) {
            return false;
        }

        return true;
    }
}

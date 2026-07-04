<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\OtpPurpose;
use App\Modules\Identity\Domain\Exceptions\OtpExpiredException;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidException;
use App\Modules\Identity\Domain\Exceptions\OtpRateLimitedException;
use App\Modules\Identity\Infrastructure\Models\OtpCode;
use App\Modules\Identity\Jobs\SendOtpEmailJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OtpService
{
    private int $otpLength;
    private int $ttlSeconds;
    private int $maxRequests;
    private int $windowSeconds;
    private int $maxAttempts;

    public function __construct()
    {
        $this->otpLength     = (int) config('kafo.otp_length', 6);
        $this->ttlSeconds    = (int) config('kafo.otp_ttl_seconds', 300);
        $this->maxRequests   = (int) config('kafo.otp_max_requests_per_window', 3);
        $this->windowSeconds = (int) config('kafo.otp_window_seconds', 900);
        $this->maxAttempts   = (int) config('kafo.otp_max_attempts', 5);
    }

    public function request(string $phone, OtpPurpose $purpose, ?string $ip = null): void
    {
        $rateLimitKey = "otp:rate:{$phone}:{$purpose->value}";
        $requestCount = (int) Cache::get($rateLimitKey, 0);

        if ($requestCount >= $this->maxRequests) {
            throw new OtpRateLimitedException();
        }

        Cache::put($rateLimitKey, $requestCount + 1, $this->windowSeconds);

        // Invalidate previous pending OTPs for this phone+purpose
        OtpCode::where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code     = str_pad((string) random_int(0, (int) str_repeat('9', $this->otpLength)), $this->otpLength, '0', STR_PAD_LEFT);
        $codeHash = Hash::make($code);

        OtpCode::create([
            'phone'      => $phone,
            'purpose'    => $purpose->value,
            'code_hash'  => $codeHash,
            'ip_address' => $ip,
            'expires_at' => now()->addSeconds($this->ttlSeconds),
        ]);

        if (config('kafo.sms_driver', 'log') === 'log') {
            Log::info("OTP [{$purpose->value}] {$phone}: {$code}");
        } else {
            $this->sendSms($phone, $code);
        }

        if ($email = config('kafo.otp_notify_email')) {
            SendOtpEmailJob::dispatch(
                $email,
                $phone,
                $purpose->value,
                $code,
                $this->ttlSeconds,
            );
        }
    }

    public function verify(string $phone, string $code, OtpPurpose $purpose): void
    {
        $otp = OtpCode::where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if (! $otp || $otp->isExpired()) {
            usleep(200_000);
            throw new OtpExpiredException();
        }

        $otp->increment('attempts');

        if ($otp->attempts >= $this->maxAttempts) {
            $otp->update(['consumed_at' => now()]);
            throw new OtpInvalidException();
        }

        if (! Hash::check($code, $otp->code_hash)) {
            usleep(200_000);
            throw new OtpInvalidException();
        }

        $otp->update(['consumed_at' => now()]);
    }

    private function sendSms(string $phone, string $code): void
    {
        // TODO: integrate Africa's Talking or Twilio in production
        Log::warning("SMS driver not configured. OTP for {$phone}: {$code}");
    }
}

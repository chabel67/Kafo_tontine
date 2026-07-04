<?php

namespace App\Modules\Identity\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOtpEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public int $backoff = 10;

    public function __construct(
        public string $email,
        public string $phone,
        public string $purpose,
        public string $code,
        public int $ttlSeconds,
    ) {}

    public function handle(): void
    {
        Mail::raw(
            "Kafo — Code OTP\n\n"
            . "Téléphone : {$this->phone}\n"
            . "Type      : {$this->purpose}\n"
            . "Code      : {$this->code}\n"
            . 'Expire dans ' . intdiv($this->ttlSeconds, 60) . " min.\n",
            fn ($m) => $m->to($this->email)->subject("[Kafo] OTP {$this->phone}")
        );
    }
}

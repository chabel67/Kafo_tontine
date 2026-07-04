<?php

return [
    'sms_driver'               => env('SMS_DRIVER', 'log'),
    'otp_length'               => (int) env('OTP_LENGTH', 6),
    'otp_ttl_seconds'          => (int) env('OTP_TTL_SECONDS', 300),
    'otp_max_requests_per_window' => (int) env('OTP_MAX_REQUESTS_PER_WINDOW', 3),
    'otp_window_seconds'       => (int) env('OTP_WINDOW_SECONDS', 900),
    'otp_max_attempts'         => (int) env('OTP_MAX_ATTEMPTS', 5),

    'otp_notify_email'         => env('OTP_NOTIFY_EMAIL'),
];

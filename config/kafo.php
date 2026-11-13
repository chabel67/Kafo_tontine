<?php

return [
    'sms_driver'               => env('SMS_DRIVER', 'log'),
    'otp_length'               => (int) env('OTP_LENGTH', 6),
    'otp_ttl_seconds'          => (int) env('OTP_TTL_SECONDS', 300),
    'otp_max_requests_per_window' => (int) env('OTP_MAX_REQUESTS_PER_WINDOW', 3),
    'otp_window_seconds'       => (int) env('OTP_WINDOW_SECONDS', 900),
    'otp_max_attempts'         => (int) env('OTP_MAX_ATTEMPTS', 5),

    'otp_notify_email'         => env('OTP_NOTIFY_EMAIL'),

    // Fuseau métier (jour de caisse, doublon cash R-PAY-08, journal R-LEDGER-08,
    // exports, stats du jour). Le stockage reste en UTC (APP_TIMEZONE).
    // « Africa/Cotonou » n'existe pas dans la base IANA : le Bénin est
    // « Africa/Porto-Novo » (UTC+1, sans heure d'été).
    'business_timezone'        => env('KAFO_BUSINESS_TIMEZONE', 'Africa/Porto-Novo'),

    // Inactivité maximale d'une session back-office avant expiration (R-AUTH-01, DA-10).
    'staff_idle_minutes'       => (int) env('KAFO_STAFF_IDLE_MINUTES', 30),
];

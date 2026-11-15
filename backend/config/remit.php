<?php

return [
    'platform_admin_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('REMIT_PLATFORM_ADMIN_EMAILS', '')),
    ))),
    'local_login_email' => env('REMIT_LOCAL_LOGIN_EMAIL'),
    'local_rate_ten_thousandths' => env('REMIT_LOCAL_RATE_TEN_THOUSANDTHS'),
    'local_sms_login' => env('REMIT_LOCAL_SMS_LOGIN', false),
    'sms' => [
        'channel' => env('REMIT_SMS_CHANNEL', 'log'),
        'code_ttl' => (int) env('REMIT_SMS_CODE_TTL', 300),
        'max_attempts' => (int) env('REMIT_SMS_MAX_ATTEMPTS', 5),
        'test_autofill' => env('REMIT_SMS_TEST_AUTOFILL', false),
    ],
];

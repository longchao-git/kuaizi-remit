<?php

return [
    'base_url' => env('YABAND_REMIT_BASE_URL'),
    'mid' => env('YABAND_REMIT_MID'),
    'password' => env('YABAND_REMIT_PASSWORD'),
    'secret_key' => env('YABAND_REMIT_SECRET_KEY'),
    'kyc_redirect_url' => env('YABAND_REMIT_KYC_REDIRECT_URL'),
    'kyc_notify_url' => env('YABAND_REMIT_KYC_NOTIFY_URL'),
    'external_uid_prefix' => env('YABAND_REMIT_EXTERNAL_UID_PREFIX'),
    'reference_rate_enabled' => env('YABAND_REMIT_REFERENCE_RATE_ENABLED', false),
    'order_sync' => [
        'enabled' => env('YABAND_REMIT_ORDER_SYNC_ENABLED', false),
        'interval_seconds' => env('YABAND_REMIT_ORDER_SYNC_INTERVAL_SECONDS', 300),
        'batch_size' => env('YABAND_REMIT_ORDER_SYNC_BATCH_SIZE', 10),
    ],
];

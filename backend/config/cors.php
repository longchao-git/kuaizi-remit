<?php

return [
    // 根路径与旧/api路径均支持后台跨域，接口鉴权仍使用Bearer令牌。
    'paths' => ['*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env(
        'REMIT_CORS_ALLOWED_ORIGINS',
        'https://admin.kzremit.com,https://api.kzremit.com,http://localhost:10099,http://127.0.0.1:10099,http://localhost:5173,http://127.0.0.1:5173',
    ))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => false,
];

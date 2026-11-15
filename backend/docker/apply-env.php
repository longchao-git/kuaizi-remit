<?php

/*
 * 首次生成 .env 时，把容器环境变量写入文件。
 *
 * `php artisan serve` 启动的子进程不会继承 compose 注入的所有环境变量，
 * 只会读取 .env，因此数据库配置必须落到 .env 里。
 * 已存在的 .env 不会被改动，避免覆盖开发者本地配置。
 */

$file = $argv[1] ?? '.env';

if (! is_file($file)) {
    fwrite(STDERR, "缺少 {$file}\n");
    exit(1);
}

$contents = file_get_contents($file);

$keys = [
    'APP_URL',
    'DB_CONNECTION',
    'DB_HOST',
    'DB_PORT',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_PASSWORD',
];

foreach ($keys as $key) {
    $value = getenv($key);

    if ($value === false) {
        continue;
    }

    // 同时匹配注释掉的示例行（如 "# DB_HOST=127.0.0.1"）。
    $pattern = '/^[#\s]*'.preg_quote($key, '/').'=.*$/m';

    if (preg_match($pattern, $contents) === 1) {
        $contents = preg_replace($pattern, $key.'='.$value, $contents, 1);
    } else {
        $contents = rtrim($contents, "\n")."\n".$key.'='.$value."\n";
    }
}

file_put_contents($file, $contents);

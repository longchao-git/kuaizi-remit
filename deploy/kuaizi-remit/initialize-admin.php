<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require '/srv/app/vendor/autoload.php';
$app = require '/srv/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$code = Artisan::call('remit:create-admin', [
    'email' => $input['email'],
    '--name' => '筷子闪汇测试管理员',
    '--password' => $input['password'],
    '--no-interaction' => true,
]);
echo Artisan::output();
exit($code);

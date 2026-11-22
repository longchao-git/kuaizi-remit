<?php

use App\Service\Notification\PushReminderService;
use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandTransport;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('remit:send-due-reminders', function (PushReminderService $reminders) {
    $count = $reminders->runDue();
    $this->info("已为 {$count} 名计划收件人保存站内提醒");
})->purpose('处理到时的首单汇款提醒');

Artisan::command('remit:check-yaband', function (YabandTransport $transport): int {
    try {
        $transport->token();
    } catch (YabandException $error) {
        $this->error($error->getMessage());

        return Command::FAILURE;
    } catch (LockTimeoutException) {
        $this->error('正在刷新雅本 Token，请稍后重试。');

        return Command::FAILURE;
    }

    $this->info('雅本 Token 鉴权通过。');
    $secretKey = config('yaband_remit.secret_key');
    $this->line('签名密钥：'.(is_string($secretKey) && trim($secretKey) !== '' ? '已配置' : '待配置'));
    $this->line('请求签名：'.(app()->bound(YabandRemitSigner::class) ? '已注册' : '待实现'));
    $this->line('回调验签：'.(app()->bound(YabandRemitCallbackVerifier::class) ? '已注册' : '待实现'));

    return Command::SUCCESS;
})->purpose('检查雅本汇款 Token 鉴权与签名组件的配置状态');

Schedule::command('remit:send-due-reminders')->everyMinute()->withoutOverlapping(30);

if (config('yaband_remit.order_sync.enabled') === true) {
    Schedule::command('remit:sync-orders')->everyMinute()->withoutOverlapping(60);
}

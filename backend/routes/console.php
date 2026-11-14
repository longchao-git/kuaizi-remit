<?php

use App\Service\Notification\PushReminderService;
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

Schedule::command('remit:send-due-reminders')->everyMinute()->withoutOverlapping(30);

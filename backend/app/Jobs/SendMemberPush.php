<?php

namespace App\Jobs;

use App\Service\Notification\MemberPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SendMemberPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public array $backoff = [30, 120];

    public function __construct(
        public int $member,
        public int $notice,
        public int $device,
        public int $token,
        public ?int $delivery = null,
    ) {
        $this->onQueue('member-push');
    }

    public function handle(MemberPushService $push): void
    {
        $push->deliver($this->member, $this->notice, $this->device, $this->token, $this->delivery ?? null);
    }

    public function failed(?Throwable $error): void
    {
        app(MemberPushService::class)->finishReminder($this->delivery ?? null, 'failed');
    }
}

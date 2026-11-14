<?php

namespace App\Service\Notification;

use App\Common\Result;
use App\Jobs\SendMemberPush;
use App\Model\Notification\MemberNotification;
use App\Repository\Notification\MemberNotificationRepository;
use App\Repository\Notification\MemberPushDeviceRepository;
use App\Repository\Notification\PushReminderDeliveryRepository;
use App\Repository\Notification\PushReminderSendRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

#[Singleton]
final class MemberPushService
{
    public function __construct(
        private MemberPushDeviceRepository $devices,
        private MemberNotificationRepository $notices,
        private DatabaseManager $database,
        private PushReminderSendRepository $reminderSends,
        private PushReminderDeliveryRepository $reminderDeliveries,
    ) {}

    public function register(int $member, int $token, string $cid): Result
    {
        if (! $this->enabled()) {
            return Result::fail('系统推送暂未开通', 503);
        }

        return $this->database->transaction(function () use ($member, $token, $cid): Result {
            $this->devices->bind($member, $token, $cid);

            return Result::success(true);
        }, attempts: 3);
    }

    public function unregister(int $member, int $token): void
    {
        $this->devices->unbind($member, $token);
    }

    /** 站内消息提交成功后再入推送队列。 */
    public function enqueue(MemberNotification $notice): void
    {
        $this->database->afterCommit(fn () => rescue(fn () => $this->dispatchForNotice($notice)));
    }

    private function dispatchForNotice(MemberNotification $notice): void
    {
        $send = $this->reminderSends->forNotice($notice->id);
        try {
            $enabled = $this->enabled();
            $devices = $enabled ? $this->devices->recipients($notice->member_id) : [];
            foreach ($devices as $device) {
                $delivery = $send === null ? null : $this->reminderDeliveries->prepare($send->id, $device['id'], $device['token_id']);
                try {
                    SendMemberPush::dispatch($notice->member_id, $notice->id, $device['id'], $device['token_id'], $delivery?->id);
                } catch (Throwable $error) {
                    report($error);
                    $this->finishReminder($delivery?->id, 'failed');
                }
            }
            if ($send !== null) {
                $this->reminderSends->setPushStatus($send, match (true) {
                    ! $enabled => 'disabled',
                    $devices === [] => 'no_device',
                    default => 'processed',
                });
            }
        } catch (Throwable $error) {
            if ($send !== null) {
                $this->reminderSends->setPushStatus($send, 'dispatch_failed');
            }
            throw $error;
        }
    }

    public function deliver(int $member, int $notice, int $device, int $token, ?int $delivery = null): void
    {
        if ($delivery !== null && ! $this->reminderDeliveries->awaiting($delivery)) {
            return;
        }
        if (! $this->enabled()) {
            $this->finishReminder($delivery, 'skipped');

            return;
        }
        $target = $this->devices->recipient($member, $device, $token);
        $message = $this->notices->findOwned($member, $notice);
        if ($target === null || $message === null || $message->read_at !== null || $message->created_at->lt(now()->subHour())) {
            $this->finishReminder($delivery, 'skipped');

            return;
        }

        try {
            $response = Http::asJson()->acceptJson()->withToken(config('member_push.secret'))
                ->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->post(config('member_push.url'), [
                    'cid' => $target->client_id,
                    'notice_id' => (string) $notice,
                    'request_id' => substr(hash('sha256', "$notice:$device:$token"), 0, 32),
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('无法连接 UniPush 转发服务。');
        }
        if (! $response->successful() || $response->json('accepted') !== true) {
            throw new RuntimeException('UniPush 转发服务未接受通知。');
        }
        $this->finishReminder($delivery, 'accepted');
    }

    public function finishReminder(?int $delivery, string $status): void
    {
        if ($delivery !== null) {
            $this->reminderDeliveries->finish($delivery, $status);
        }
    }

    private function enabled(): bool
    {
        $url = config('member_push.url');
        $secret = config('member_push.secret');

        return config('member_push.enabled') === true
            && is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_USER) === null
            && is_string($secret) && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $secret);
    }
}

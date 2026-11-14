<?php

namespace App\Service\Notification;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Notification\MemberNotification;
use App\Repository\Notification\MemberNotificationRepository;
use App\Repository\Remittance\RemittanceOrderRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Broadcast;

/** 按当前账号管理站内消息及已读状态。 */
#[Singleton]
final class MemberNotificationService
{
    /** 使用数据库持久化通知。 */
    public function __construct(
        private MemberNotificationRepository $notices,
        private MemberPushService $push,
        private RemittanceOrderRepository $orders,
    ) {}

    /** 返回指定会员的消息及全量未读数，最新消息在前。 */
    public function page(int $member, int $page): Result
    {
        $rows = $this->notices->page($member, $page);
        $items = array_map($this->format(...), $rows->items());

        return Result::success([
            ...PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray(),
            'unread_count' => $this->notices->unreadCount($member),
        ]);
    }

    /** 标记一条或当前列表最新消息及之前的消息，重复调用保持结果一致。 */
    public function markRead(int $member, int $notice, bool $through = false): Result
    {
        if ($this->notices->findOwned($member, $notice) === null) {
            return Result::fail('消息不存在', 404);
        }

        $changed = $this->notices->markRead($member, $notice, $through);
        if ($changed > 0) {
            $this->refreshInbox($member);
        }

        return Result::success(['unread_count' => $this->notices->unreadCount($member)]);
    }

    public function show(int $member, int $notice): Result
    {
        $owned = $this->notices->findOwned($member, $notice);

        return $owned === null ? Result::fail('消息不存在', 404) : Result::success($this->format($owned));
    }

    /** 写入一条运营通知。相同请求重试返回原记录。 */
    public function send(int $member, ?int $staff, array $input, bool $broadcast = true): Result
    {
        $order = $input['order_reference'] ?? null;
        if ($order !== null && $this->orders->find($order, $member) === null) {
            return Result::fail('订单不存在', 404);
        }
        $notice = $this->notices->createOnce(strtolower($input['request_id']), [
            'member_id' => $member,
            'sender_id' => $staff,
            'title' => $input['title'],
            'body' => $input['body'],
            'support_ticket_id' => $input['support_ticket_id'] ?? null,
            'order_reference' => $order,
        ]);

        if ($notice->member_id !== $member || $notice->sender_id !== $staff
            || $notice->support_ticket_id !== ($input['support_ticket_id'] ?? null)
            || $notice->order_reference !== $order
            || $notice->title !== $input['title'] || $notice->body !== $input['body']) {
            return Result::fail('发送请求已用于其他消息', 409);
        }

        if ($notice->wasRecentlyCreated) {
            if ($broadcast) {
                $this->refreshInbox($member);
            }
            $this->push->enqueue($notice);
        }

        return Result::success($this->format($notice), '已发送站内通知');
    }

    public function unread(int $member): Result
    {
        return Result::success(['unread_count' => $this->notices->unreadCount($member)]);
    }

    public function refreshInbox(int $member): void
    {
        rescue(fn () => Broadcast::private('users.'.$member)->as('notifications.changed')->with([])->sendNow());
    }

    /** 返回页面所需字段，隐藏会员归属和内部发送请求标识。 */
    private function format(MemberNotification $notice): array
    {
        return [
            'id' => $notice->id,
            'reference' => (string) $notice->id,
            'title' => $notice->title,
            'body' => $notice->body,
            'support_ticket_id' => $notice->support_ticket_id === null ? null : (string) $notice->support_ticket_id,
            'order_reference' => $notice->order_reference,
            'read_at' => $notice->read_at?->toISOString(),
            'created_at' => $notice->created_at->toISOString(),
        ];
    }
}

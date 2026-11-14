<?php

namespace App\Service\Support;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Support\SupportMessage;
use App\Repository\Support\SupportMessageRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Broadcast;

#[Singleton]
final class SupportMessageService
{
    /** 使用数据库保存会话消息及读取进度。 */
    public function __construct(private SupportMessageRepository $messages, private DatabaseManager $database) {}

    /** 查询消息和断线补收游标，不在读取接口内改变已读状态。 */
    public function history(int $memberId, ?int $after): Result
    {
        $messages = $this->messages->history($memberId, $after);
        $items = $messages->take(50);

        return Result::success([
            'list' => $items->map($this->format(...))->all(),
            'next_after_id' => $items->last()?->id ?? $after,
            'has_more' => $messages->count() > 50,
        ]);
    }

    /** 保存消息，重复发送保持幂等，落库后广播。 */
    public function send(int $memberId, int $senderId, string $clientId, string $body): Result
    {
        $message = $this->database->transaction(
            fn () => $this->messages->createOnce($memberId, $senderId, strtolower($clientId), $body),
            attempts: 3,
        );
        if ($message->body !== $body) {
            return Result::fail('同一消息编号不能提交不同内容', 409);
        }

        if ($message->wasRecentlyCreated) {
            $event = ['member_id' => $memberId, 'message_id' => $message->id];
            rescue(fn () => Broadcast::private('users.'.$memberId)->as('support.message')->with($event)->sendNow());
            rescue(fn () => Broadcast::private('support.staff')->as('support.message')->with([])->sendNow());
        }

        return Result::success($this->format($message));
    }

    /** 查询会员未读数，供 App 客服入口展示。 */
    public function memberStatus(int $memberId): Result
    {
        return Result::success(['unread_count' => $this->messages->unreadCount($memberId, 'member')]);
    }

    /** 只确认实际存在于该会话中的消息编号，成功推进后通知同一端的其他会话。 */
    public function read(int $memberId, string $side, int $messageId): Result
    {
        if (! $this->messages->belongsToMember($memberId, $messageId)) {
            return Result::fail('客服消息不存在', 404);
        }

        if ($this->messages->advanceRead($memberId, $side, $messageId)) {
            $channel = $side === 'staff' ? 'support.staff' : 'users.'.$memberId;
            rescue(fn () => Broadcast::private($channel)->as('support.read')->with([])->sendNow());
        }

        return Result::success(['unread_count' => $this->messages->unreadCount($memberId, $side)]);
    }

    /** 返回后台会话列表及由所有客服共享的未读数。 */
    public function conversations(int $page, bool $onlyUnread): Result
    {
        $rows = $this->messages->conversations($page, $onlyUnread);
        $items = array_map(fn (SupportMessage $message) => [
            'member_id' => (string) $message->member_id,
            'member_name' => $message->member_name,
            'last_message' => $this->format($message),
            'unread_count' => (int) $message->unread_count,
        ], $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }

    private function format(SupportMessage $message): array
    {
        return [
            'id' => $message->id,
            'sender' => (int) $message->sender_id === (int) $message->member_id ? 'member' : 'staff',
            'client_id' => $message->client_id,
            'body' => $message->body,
            'created_at' => $message->created_at->toISOString(),
        ];
    }
}

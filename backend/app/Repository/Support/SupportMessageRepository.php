<?php

namespace App\Repository\Support;

use App\Model\Support\SupportMessage;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** @extends AbstractRepository<SupportMessage> */
final class SupportMessageRepository extends AbstractRepository
{
    protected function model(): SupportMessage
    {
        return new SupportMessage;
    }

    /** @return Collection<int, SupportMessage> */
    public function history(int $memberId, ?int $after): Collection
    {
        $query = $this->query()->where('member_id', $memberId);
        if ($after !== null) {
            return $query->where('id', '>', $after)->orderBy('id')->limit(51)->get();
        }

        return $query->orderByDesc('id')->limit(50)->get()->reverse()->values();
    }

    public function createOnce(int $memberId, int $senderId, string $clientId, string $body): SupportMessage
    {
        // 同一会话串行写入，避免较小的消息编号晚于较大的编号提交。
        DB::table('users')->where('id', $memberId)->lockForUpdate()->first(['id']);

        return $this->query()->createOrFirst([
            'member_id' => $memberId,
            'sender_id' => $senderId,
            'client_id' => $clientId,
        ], ['body' => $body]);
    }

    /** 非本方发送且在读取进度之后的消息数量。 */
    public function unreadCount(int $memberId, string $side): int
    {
        $operator = $side === 'staff' ? '=' : '!=';
        $cursor = DB::table('support_read_cursors')->select('last_read_id')
            ->where('member_id', $memberId)->where('side', $side);

        return $this->query()->where('member_id', $memberId)->whereColumn('sender_id', $operator, 'member_id')
            ->whereRaw('id > COALESCE(('.$cursor->toSql().'), 0)', $cursor->getBindings())->count();
    }

    /** 验证客服消息属于当前会话。 */
    public function belongsToMember(int $memberId, int $messageId): bool
    {
        return $this->query()->where('member_id', $memberId)->whereKey($messageId)->exists();
    }

    /** 原子推进读取进度。旧请求和重复请求都不会回退。 */
    public function advanceRead(int $memberId, string $side, int $messageId): bool
    {
        $cursors = DB::table('support_read_cursors');
        $created = $cursors->insertOrIgnore(['member_id' => $memberId, 'side' => $side, 'last_read_id' => $messageId]);
        if ($created === 1) {
            return true;
        }

        return $cursors->where('member_id', $memberId)->where('side', $side)->where('last_read_id', '<', $messageId)
            ->update(['last_read_id' => $messageId]) === 1;
    }

    /** 列出已有对话的会员，按最近消息排序，可只看客服未读。 */
    public function conversations(int $page, bool $onlyUnread): LengthAwarePaginator
    {
        $latest = $this->query()->selectRaw('member_id, MAX(id) AS message_id')->groupBy('member_id');
        $unread = $this->query()->from('support_messages as incoming')
            ->leftJoin('support_read_cursors as cursors', function (JoinClause $join): void {
                $join->on('cursors.member_id', '=', 'incoming.member_id')->where('cursors.side', 'staff');
            })
            ->whereColumn('incoming.sender_id', 'incoming.member_id')
            ->whereRaw('incoming.id > COALESCE(cursors.last_read_id, 0)')
            ->selectRaw('incoming.member_id, COUNT(*) AS unread_count')->groupBy('incoming.member_id');

        $query = $this->query()->joinSub($latest, 'latest', 'latest.message_id', '=', 'support_messages.id')
            ->join('users', 'users.id', '=', 'latest.member_id')
            ->leftJoinSub($unread, 'unread', 'unread.member_id', '=', 'latest.member_id')
            ->where('users.is_platform_staff', false);
        if ($onlyUnread) {
            $query->whereNotNull('unread.unread_count');
        }

        return $query->orderByDesc('support_messages.id')->paginate(20, [
            'support_messages.*', 'users.name as member_name', 'unread.unread_count',
        ], page: $page);
    }
}

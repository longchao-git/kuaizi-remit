<?php

namespace App\Service\Remittance\Yaband;

use App\Common\Result;
use App\Repository\Remittance\YabandMaterialRequestRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Repository\System\UserRepository;
use App\Service\Notification\MemberNotificationService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use JsonException;
use stdClass;

/** 已验签的question通知只定位已有会员，任务及提醒一起落库，不从通知创建身份或订单。 */
final class MemberMaterialNoticeService
{
    /** 复用现有会员绑定、补件任务和站内通知，不增加独立问卷提交渠道。 */
    public function __construct(
        private YabandMaterialRequestRepository $tasks,
        private YabandRemitMemberRepository $links,
        private UserRepository $users,
        private MemberNotificationService $notices,
        private DatabaseManager $database,
        private MemberMaterialReminder $reminder,
    ) {}

    /** 签名在Controller先核对；此处校验问卷内容并与身份变更、注销共用会员锁。 */
    public function receive(array $data): Result
    {
        $user = $this->decodeUser($data);
        if ($user === null) {
            return Result::fail('补件通知内容不完整或格式不正确', 422);
        }
        $link = $this->links->findByUid((string) $user->uid);
        if ($link === null) {
            return Result::fail('雅本用户尚未绑定', 404);
        }
        $member = (int) $link->member_id;
        $lock = Cache::lock('yaband-remit:kyc:'.$member, 75);
        if (! $lock->get()) {
            return Result::fail('会员身份或补件通知正在处理，请稍后重试', 409);
        }
        try {
            $result = $this->database->transaction(fn (): Result => $this->record($member, $user, $data));
            if ($result->isSuccess()) {
                $this->notices->refreshInbox($member);
            }

            return $result;
        } catch (YabandException) {
            return Result::fail('补件任务与提醒尚未保存成功，请重试通知', 503);
        } finally {
            $lock->release();
        }
    }

    /** 使用原始JSON字符串解析；当前文档transaction只允许空数组，不猜测未来订单字段。 */
    private function decodeUser(array $data): ?stdClass
    {
        if (! is_string($data['qno'] ?? null) || trim($data['qno']) === '' || mb_strlen($data['qno'], 'UTF-8') > 200
            || ! is_string($data['q_description'] ?? null) || trim($data['q_description']) === '' || strlen($data['q_description']) > 65535
            || ! is_int($data['timeout_at'] ?? null) || $data['timeout_at'] < 1 || $data['timeout_at'] > 2147483647
            || ! is_string($data['user'] ?? null) || ! is_string($data['transaction'] ?? null)) {
            return null;
        }
        try {
            $user = json_decode($data['user'], false, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            $transaction = json_decode($data['transaction'], false, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! $user instanceof stdClass || (! is_string($user->uid ?? null) && ! is_int($user->uid ?? null))
            || (string) $user->uid === '' || strlen((string) $user->uid) > 64
            || ! is_string($user->external_uid ?? null) || trim($user->external_uid) === '' || strlen($user->external_uid) > 64
            || $transaction !== []) {
            return null;
        }

        return $user;
    }

    /** 会员行锁内再次核对绑定；相同qno只复用完全相同的任务，不延长或重开旧任务。 */
    private function record(int $member, stdClass $user, array $data): Result
    {
        $account = $this->users->lockMember($member);
        $link = $this->links->findByUid((string) $user->uid);
        if ($account === null || $link === null || (int) $link->member_id !== $member) {
            return Result::fail('雅本用户尚未绑定可接收补件的会员', 404);
        }
        if ($account->member_closed_at !== null) {
            return Result::fail('会员账号已注销，请核对补件请求', 409);
        }
        // 历史绑定没有保存external_uid时仍以已绑定UID定位，不从通知反向创建或修改身份。
        if ($link->external_uid !== null && $link->external_uid !== $user->external_uid) {
            return Result::fail('补件通知的会员关联不匹配', 403);
        }
        $deadline = Carbon::createFromTimestampUTC($data['timeout_at']);
        $task = $this->tasks->register($data['qno'], [
            'member_id' => $member, 'order_reference' => null, 'instruction' => $data['q_description'],
            'deadline_at' => $deadline, 'status' => $deadline->lte(now()) ? '6003' : '6001',
        ]);
        if ($task->question_id !== $data['qno'] || $task->member_id !== $member
            || $task->instruction !== $data['q_description'] || $task->deadline_at->timestamp !== $data['timeout_at']) {
            return Result::fail('补件问题编号已用于不同的任务，请核对通知', 409);
        }
        $this->reminder->send($task, (string) $data['mid']);

        return Result::success();
    }
}

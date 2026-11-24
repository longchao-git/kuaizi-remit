<?php

namespace App\Service\Remittance\Yaband;

use App\Model\Remittance\YabandMaterialRequest;
use App\Service\Notification\MemberNotificationService;
use Ramsey\Uuid\Uuid;

/** 人工登记及异步通知使用同一提醒标识，避免同一补件重复发消息。 */
final class MemberMaterialReminder
{
    /** 共用现有消息去重、归属检查及提交后推送机制。 */
    public function __construct(private MemberNotificationService $notices) {}

    /** 调用方须在保存任务的事务内执行；提醒失败时一起回滚。 */
    public function send(YabandMaterialRequest $task, string $mid): void
    {
        if ($task->status !== '6001' || $task->deadline_at->lte(now())) {
            return;
        }
        if ($mid === '') {
            throw new YabandException('补件提醒缺少商户配置。');
        }
        $member = (int) $task->member_id;
        $requestId = Uuid::uuid5(Uuid::NAMESPACE_URL, json_encode(['yaband-question', $mid, $member, $task->question_id], JSON_THROW_ON_ERROR));
        $result = $this->notices->send($member, null, [
            'request_id' => (string) $requestId, 'title' => '需要补充资料',
            'body' => '请查看补件要求，并在截止时间前提交。', 'material_reference' => $task->reference,
        ], broadcast: false);
        if ($result->isFail()) {
            throw new YabandException('无法保存补件提醒。');
        }
    }
}

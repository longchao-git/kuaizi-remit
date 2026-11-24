<?php

namespace App\Service\Remittance;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Remittance\MemberClosureRequest;
use App\Repository\Remittance\MemberClosureRepository;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\RemittanceOrderRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;

#[Singleton]
final class MemberClosureService
{
    /** 审核仅处理本地申请，实际注销通过独立执行入口完成。 */
    public function __construct(
        private MemberClosureRepository $requests,
        private RemittanceCouponRepository $members,
        private RemittanceOrderRepository $orders,
        private DatabaseManager $database,
    ) {}

    /** 按会员归属或员工筛选返回审核及执行状态。 */
    public function page(int $page, ?int $member, ?string $status = null, bool $staff = false): Result
    {
        $rows = $this->requests->page($page, $member, $status);
        $list = array_map(fn (MemberClosureRequest $row) => $this->format($row, $staff), $rows->items());

        return Result::success(PaginationPayload::from($list, $rows->total(), $page, $rows->perPage())->toArray());
    }

    /** 会员锁内复用尚未拒绝的申请，不重复创建注销任务。 */
    public function submit(int $member, ?string $reason): Result
    {
        return $this->database->transaction(function () use ($member, $reason): Result {
            $this->members->lockMember($member);
            $latest = $this->requests->latest($member);
            if ($latest !== null && $latest->status !== 'rejected') {
                return Result::success($this->format($latest), '已有一笔注销申请在处理中');
            }

            return Result::success($this->format($this->requests->create($member, $reason)), '注销申请已提交');
        }, attempts: 3);
    }

    /** 审核通过前核对未结清资金，但不在审核事务中调用上游停用。 */
    public function review(int $id, int $staff, string $decision, string $note): Result
    {
        return $this->database->transaction(function () use ($id, $staff, $decision, $note): Result {
            $row = $this->requests->lock($id);
            if ($row === null) {
                return Result::fail('注销申请不存在', 404);
            }
            if ($row->status !== 'pending') {
                return Result::fail('这笔申请已处理，请刷新列表', 409);
            }
            $this->members->lockMember($row->member_id);
            if ($decision === 'approved' && $this->orders->hasUnsettled($row->member_id)) {
                return Result::fail('会员仍有未结清的订单，请先核实订单或退款状态', 409);
            }
            $this->requests->review($row, $staff, $decision, $note);

            return Result::success($this->format($row, true), '审核结果已记录');
        }, attempts: 3);
    }

    /** 会员读取本人审核/执行状态，操作人与内部绑定仅在员工调用时返回。 */
    public function format(MemberClosureRequest $row, bool $staff = false): array
    {
        $result = [
            'id' => (string) $row->id,
            'status' => $row->status,
            'reason' => $row->reason,
            'review_note' => $row->review_note,
            'reviewed_at' => $row->reviewed_at?->toISOString(),
            'created_at' => $row->created_at->toISOString(),
            'execution_status' => $row->execution_status,
            'execution_note' => $row->execution_note,
            'execution_started_at' => $row->execution_started_at?->toISOString(),
            'executed_at' => $row->executed_at?->toISOString(),
        ];

        return $staff ? [...$result, 'member_id' => (string) $row->member_id, 'reviewed_by' => (string) ($row->reviewed_by ?? ''),
            'executed_by' => (string) ($row->executed_by ?? ''), 'last_checked_at' => $row->last_checked_at?->toISOString(),
            'last_checked_by' => (string) ($row->last_checked_by ?? '')] : $result;
    }
}

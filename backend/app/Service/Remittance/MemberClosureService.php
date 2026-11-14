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
    public function __construct(
        private MemberClosureRepository $requests,
        private RemittanceCouponRepository $members,
        private RemittanceOrderRepository $orders,
        private DatabaseManager $database,
    ) {}

    public function page(int $page, ?int $member, ?string $status = null, bool $staff = false): Result
    {
        $rows = $this->requests->page($page, $member, $status);
        $list = array_map(fn (MemberClosureRequest $row) => $this->format($row, $staff), $rows->items());

        return Result::success(PaginationPayload::from($list, $rows->total(), $page, $rows->perPage())->toArray());
    }

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

    private function format(MemberClosureRequest $row, bool $staff = false): array
    {
        $result = [
            'id' => (string) $row->id,
            'status' => $row->status,
            'reason' => $row->reason,
            'review_note' => $row->review_note,
            'reviewed_at' => $row->reviewed_at?->toISOString(),
            'created_at' => $row->created_at->toISOString(),
        ];

        return $staff ? [...$result, 'member_id' => (string) $row->member_id, 'reviewed_by' => (string) ($row->reviewed_by ?? '')] : $result;
    }
}

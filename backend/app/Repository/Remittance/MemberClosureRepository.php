<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\MemberClosureRequest;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/** @extends AbstractRepository<MemberClosureRequest> */
final class MemberClosureRepository extends AbstractRepository
{
    protected function model(): MemberClosureRequest
    {
        return new MemberClosureRequest;
    }

    public function latest(int $member): ?MemberClosureRequest
    {
        return $this->query()->where('member_id', $member)->orderByDesc('id')->first();
    }

    public function approved(int $member): bool
    {
        return $this->query()->where('member_id', $member)->where('status', 'approved')->exists();
    }

    public function page(int $page, ?int $member, ?string $status): LengthAwarePaginator
    {
        return $this->query()->when($member, fn ($query) => $query->where('member_id', $member))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(20, page: $page);
    }

    public function create(int $member, ?string $reason): MemberClosureRequest
    {
        return $this->query()->create(['member_id' => $member, 'reason' => $reason, 'status' => 'pending']);
    }

    public function lock(int $id): ?MemberClosureRequest
    {
        return $this->query()->lockForUpdate()->find($id);
    }

    /** 执行前仅读取申请归属，具体状态在会员锁和事务内再次核对。 */
    public function find(int $id): ?MemberClosureRequest
    {
        return $this->query()->find($id);
    }

    /** 首次执行固定请求UUID及当时的上游UID，未知结果不生成第二份请求。 */
    public function beginExecution(MemberClosureRequest $row, int $staff, ?string $uid): void
    {
        $row->forceFill([
            'execution_status' => 'processing', 'execution_request_id' => (string) Str::uuid(),
            'execution_uid' => $uid, 'execution_started_at' => now(), 'executed_by' => $staff,
            'execution_note' => '注销请求处理中',
        ])->saveOrFail();
    }

    /** 核实只记录查询操作，不修改原始请求或重复发起注销。 */
    public function recordVerification(MemberClosureRequest $row, int $staff): void
    {
        $row->forceFill(['last_checked_at' => now(), 'last_checked_by' => $staff])->saveOrFail();
    }

    /** 保留不确定结果，禁止把异常当成功或覆盖已经完成的注销。 */
    public function markUncertain(int $id, string $note): void
    {
        $this->query()->whereKey($id)->where('execution_status', '!=', 'completed')
            ->update(['execution_status' => 'pending_confirmation', 'execution_note' => $note]);
    }

    /** 本地登录关闭和执行完成由服务放在同一事务内保存。 */
    public function completeExecution(MemberClosureRequest $row, string $note): void
    {
        $row->forceFill(['execution_status' => 'completed', 'executed_at' => now(), 'execution_note' => $note])->saveOrFail();
    }

    public function review(MemberClosureRequest $row, int $staff, string $decision, string $note): void
    {
        $row->forceFill([
            'status' => $decision, 'reviewed_by' => $staff, 'review_note' => $note, 'reviewed_at' => now(),
        ])->saveOrFail();
    }
}

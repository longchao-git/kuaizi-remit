<?php

namespace App\Service\Remittance;

use App\Common\Result;
use App\Model\Remittance\MemberClosureRequest;
use App\Repository\Remittance\MemberClosureRepository;
use App\Repository\Remittance\RemittanceOrderRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Repository\System\UserRepository;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\System\TestSmsLogin;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** 仅执行已经审核通过的会员注销；未知上游结果通过查询核实，不自动重发。 */
#[Singleton]
final class MemberClosureExecutionService
{
    /** 复用审核、资金门禁、会员与上游绑定，并保留本地事务和外部调用的边界。 */
    public function __construct(
        private MemberClosureRepository $requests,
        private MemberClosureService $closures,
        private RemittanceOrderRepository $orders,
        private YabandRemitMemberRepository $links,
        private UserRepository $users,
        private RemittanceChannels $channels,
        private TestSmsLogin $testLogin,
        private DatabaseManager $database,
    ) {}

    /** 首次显式执行固定请求编号，已有未确认请求不再次调用注销接口。 */
    public function execute(int $id, int $staff): Result
    {
        return $this->withMemberLock($id, $staff, verify: false);
    }

    /** 核实已尝试的注销结果，只有明确inactive才关闭本地登录。 */
    public function verify(int $id, int $staff): Result
    {
        return $this->withMemberLock($id, $staff, verify: true);
    }

    /** 与开户/资料提交共用会员锁，防止注销过程中发生上游身份变更。 */
    private function withMemberLock(int $id, int $staff, bool $verify): Result
    {
        $request = $this->requests->find($id);
        if ($request === null) {
            return Result::fail('注销申请不存在', 404);
        }
        $lock = Cache::lock('yaband-remit:kyc:'.$request->member_id, 75);
        if (! $lock->get()) {
            return Result::fail('会员身份或注销请求正在处理，请稍后核实', 409);
        }
        try {
            return $this->perform($id, $staff, $verify);
        } finally {
            $lock->release();
        }
    }

    /** 先持久化尝试，再在事务之外调用上游；任何不确定响应都保留待核实状态。 */
    private function perform(int $id, int $staff, bool $verify): Result
    {
        $request = $this->prepare($id, $staff, $verify);
        if ($request instanceof Result) {
            return $request;
        }
        try {
            if ($request->execution_uid !== null) {
                $link = $this->links->findFor((int) $request->member_id);
                if ($link?->uid !== $request->execution_uid) {
                    throw new YabandException('注销账号绑定不一致。');
                }
                $channel = $this->channels->resolve($this->channels->storedScope($link->channel_scope));
                if ($verify) {
                    $status = $channel->queryKyc((string) Str::uuid(), $request->execution_uid);
                    if (($status['user_status_code'] ?? null) !== '1002') {
                        $this->requests->markUncertain($id, '尚未确认雅本账号已停用，请稍后核实或联系雅本处理');

                        return Result::fail('尚未确认雅本账号已停用，未重复发起注销', 409);
                    }
                } else {
                    $channel->closeAccount($request->execution_request_id, $request->execution_uid);
                }
            }

            return $this->complete($request);
        } catch (YabandException) {
            $this->requests->markUncertain($id, '上游注销结果未确认，请使用核实结果，不要重复发起');

            return Result::fail('注销结果尚未确认，请核实结果后继续处理', 503);
        }
    }

    /** 申请/会员采用一致行锁顺序，外部调用前再次检查权限对象、未结清订单及UID快照。 */
    private function prepare(int $id, int $staff, bool $verify): MemberClosureRequest|Result
    {
        return $this->database->transaction(function () use ($id, $staff, $verify): MemberClosureRequest|Result {
            $row = $this->requests->lock($id);
            if ($row === null) {
                return Result::fail('注销申请不存在', 404);
            }
            if ($row->status !== 'approved') {
                return Result::fail('只有审核通过的申请可以执行或核实注销', 409);
            }
            $member = $this->users->lockMember((int) $row->member_id);
            if ($member === null) {
                return Result::fail('找不到可注销的会员', 404);
            }
            if ($this->testLogin->allowsMember($member)) {
                return Result::fail('专用回归测试账号不执行注销', 403);
            }
            if ($row->execution_status === 'completed') {
                return Result::success($this->closures->format($row, staff: true));
            }
            if ($this->orders->hasUnsettled((int) $member->id)) {
                return Result::fail('会员仍有未结清订单，请先处理汇款或退款', 409);
            }
            $link = $this->links->findFor((int) $member->id);
            $uid = $link?->uid;
            if ($uid !== null) {
                try {
                    $this->channels->resolve($this->channels->storedScope($link->channel_scope));
                } catch (YabandException) {
                    return Result::fail('原账户注销通道暂不可用', 503);
                }
            }
            if ($verify) {
                if ($row->execution_request_id === null) {
                    return Result::fail('尚未执行注销，无法核实结果', 409);
                }
                if ($row->execution_uid !== $uid) {
                    return Result::fail('会员上游绑定已变化，请人工核对原注销请求', 409);
                }
                $this->requests->recordVerification($row, $staff);
            } else {
                if ($row->execution_status !== 'not_started' || $row->execution_request_id !== null) {
                    return Result::fail('已有注销请求，请使用核实结果，勿重复发起', 409);
                }
                $this->requests->beginExecution($row, $staff, $uid);
            }

            return $row;
        });
    }

    /** 上游停用确认后再次检查资金与绑定，同一事务内关闭登录、撤销令牌并完成申请。 */
    private function complete(MemberClosureRequest $request): Result
    {
        return $this->database->transaction(function () use ($request): Result {
            $row = $this->requests->lock((int) $request->id);
            $member = $this->users->lockMember((int) $request->member_id);
            if ($row === null || $member === null || $row->execution_request_id !== $request->execution_request_id) {
                return Result::fail('注销记录已变化，请人工核实处理结果', 409);
            }
            if ($row->execution_status === 'completed') {
                return Result::success($this->closures->format($row, staff: true));
            }
            if ($row->status !== 'approved' || $this->links->findFor((int) $member->id)?->uid !== $row->execution_uid
                || $this->orders->hasUnsettled((int) $member->id)) {
                $this->requests->markUncertain((int) $row->id, '上游停用已确认，本地绑定或未结清业务需核对');

                return Result::fail('上游停用已确认，本地仍有待核对业务，请处理后核实结果', 409);
            }
            $this->users->closeMember($member);
            $link = $this->links->findFor((int) $member->id);
            if ($link !== null && $row->execution_uid !== null) {
                $this->links->saveState($link, $row->execution_uid, [
                    ...($link->statuses ?? []), 'user_status_code' => '1002', 'remittance_enabled' => false,
                    'can_start_idv' => false, 'can_submit_profile' => false,
                ]);
            }
            $this->requests->completeExecution($row, '账号注销已完成，登录已关闭；历史业务记录保留');

            return Result::success($this->closures->format($row, staff: true), '账号注销已完成');
        });
    }
}

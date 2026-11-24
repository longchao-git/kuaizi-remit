<?php

namespace App\Service\Remittance\Yaband;

use App\Common\Result;
use App\Model\Remittance\YabandRemitMember;
use App\Model\System\User;
use App\Repository\Remittance\MemberClosureRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Notification\MemberNotificationService;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

#[Singleton]
final class MemberKycService
{
    /** 复用会员、通知和事务，并在身份变更前核对注销审核状态。 */
    public function __construct(
        private YabandRemitClient $client,
        private YabandRemitMemberRepository $members,
        private MemberNotificationService $notices,
        private DatabaseManager $database,
        private MemberClosureRepository $closures,
    ) {}

    public function status(User $member): Result
    {
        return $this->withMemberLock((int) $member->getKey(), function () use ($member): Result {
            $link = $this->members->findFor((int) $member->getKey());
            if ($link?->uid === null) {
                return Result::success(['registered' => false]);
            }

            $status = $this->client->queryKyc((string) Str::uuid(), $link->uid);
            $this->syncState($link, $status);

            return Result::success(['registered' => true, ...$status]);
        });
    }

    /** 通知只用于定位已绑定会员，认证和过期状态以最新上游查询为准。 */
    public function refreshNotified(string $uid): Result
    {
        $link = $this->members->findByUid($uid);
        if ($link === null) {
            return Result::fail('雅本用户尚未绑定', 404);
        }

        return $this->withMemberLock((int) $link->member_id, function () use ($uid): Result {
            $current = $this->members->findByUid($uid);
            if ($current === null) {
                return Result::fail('雅本用户尚未绑定', 404);
            }
            $status = $this->client->queryKyc((string) Str::uuid(), $uid);
            $this->syncState($current, $status);

            return Result::success();
        });
    }

    /** 已通过注销审核的账号不再开启或创建上游身份会话。 */
    public function start(User $member): Result
    {
        $prefix = config('yaband_remit.external_uid_prefix');
        $redirect = config('yaband_remit.kyc_redirect_url');
        $notify = config('yaband_remit.kyc_notify_url');
        if (! is_string($prefix) || ! preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $prefix)
            || ! $this->validUrl($redirect) || ! $this->validUrl($notify)) {
            return Result::fail('身份验证暂未开放', 503);
        }
        $contact = $member->phone_e164 === null ? ['email' => $member->email] : ['phone' => $member->phone_e164];
        if ($member->phone_e164 === null && (! filter_var($member->email, FILTER_VALIDATE_EMAIL) || strlen($member->email) > 64)) {
            return Result::fail('请使用有效的联系方式进行身份验证', 409);
        }

        return $this->withMemberLock((int) $member->getKey(), function () use ($member, $prefix, $redirect, $notify, $contact): Result {
            $id = (int) $member->getKey();
            if ($this->closures->approved($id)) {
                return Result::fail('账号正在注销，暂不能发起身份验证', 409);
            }
            $link = $this->members->getOrCreate($id);
            $uid = $link->uid;
            if ($uid === null) {
                $uid = $this->client->createMember($link->create_request_id, $notify, [
                    'external_uid' => $prefix.'-'.$id,
                    ...$contact,
                ]);
                $this->members->saveState($link, $uid, null);
            }

            $status = $this->client->queryKyc((string) Str::uuid(), $uid);
            $this->syncState($link, $status);
            if (! $status['can_start_idv']) {
                return Result::fail('当前状态无法发起身份验证，请先查看认证进度', 409);
            }
            $url = $this->client->startIdv((string) Str::uuid(), $uid, $redirect, $notify);

            return Result::success(['url' => $url]);
        });
    }

    /** 会员锁内重新核对注销状态及IDV资格，再提交资料。 */
    public function submitProfile(User $member, array $profile): Result
    {
        $notify = config('yaband_remit.kyc_notify_url');
        if (! $this->validUrl($notify)) {
            return Result::fail('认证资料提交暂未开放', 503);
        }

        return $this->withMemberLock((int) $member->getKey(), function () use ($member, $profile, $notify): Result {
            if ($this->closures->approved((int) $member->getKey())) {
                return Result::fail('账号正在注销，暂不能提交认证资料', 409);
            }
            $link = $this->members->findFor((int) $member->getKey());
            if ($link?->uid === null) {
                return Result::fail('请先发起身份验证', 409);
            }
            $status = $this->client->queryKyc((string) Str::uuid(), $link->uid);
            if (! $status['can_submit_profile']) {
                return Result::fail('当前状态无法提交认证资料', 409);
            }

            $result = $this->client->submitProfile((string) Str::uuid(), $link->uid, $notify, $profile);
            if ((string) ($result['uid'] ?? '') !== $link->uid) {
                throw new YabandException('雅本资料响应的用户编号不匹配。');
            }
            $status = YabandKycStatus::fromQuery($result);
            $this->syncState($link, $status);

            return Result::success(['registered' => true, ...$status]);
        });
    }

    /** 同一状态重复查询时只保存快照，不重复发送站内通知。 */
    private function syncState(YabandRemitMember $link, array $status): void
    {
        $before = $link->statuses ?? [];
        $notice = match (true) {
            ($before['kyc_passed'] ?? false) === false && $status['kyc_passed'] => ['身份认证已通过', '可在身份认证页面查看最新状态。'],
            ! $this->requiresDocumentRenewal($before) && $this->requiresDocumentRenewal($status) => ['请更新身份认证资料', '请在身份认证页面重新完成身份验证。'],
            default => null,
        };

        $this->database->transaction(function () use ($link, $status, $notice): void {
            $this->members->saveState($link, $link->uid, $status);
            if ($notice !== null) {
                $this->notices->send((int) $link->member_id, null, [
                    'request_id' => (string) Str::uuid(), 'title' => $notice[0], 'body' => $notice[1],
                ], broadcast: false);
            }
        });
        if ($notice !== null) {
            $this->notices->refreshInbox((int) $link->member_id);
        }
    }

    private function requiresDocumentRenewal(array $status): bool
    {
        return ($status['kyc_status_code'] ?? null) === '5003' && ($status['remit_status_code'] ?? null) === '4002'
            && ($status['idv_status_code'] ?? null) === '2002';
    }

    private function withMemberLock(int $member, callable $action): Result
    {
        if (! app()->bound(YabandRemitSigner::class)) {
            return Result::fail('身份验证暂未开放', 503);
        }
        try {
            return Cache::lock('yaband-remit:kyc:'.$member, 75)->block(3, $action);
        } catch (LockTimeoutException) {
            return Result::fail('身份验证请求正在处理，请稍后再试', 409);
        } catch (YabandException) {
            return Result::fail('暂时无法获取雅本认证结果，请稍后再试', 502);
        }
    }

    private function validUrl(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 200 && filter_var($value, FILTER_VALIDATE_URL)
            && parse_url($value, PHP_URL_SCHEME) === 'https' && parse_url($value, PHP_URL_USER) === null;
    }
}

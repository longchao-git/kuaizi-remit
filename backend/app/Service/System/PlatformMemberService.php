<?php

namespace App\Service\System;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\System\User;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Repository\System\UserRepository;
use Illuminate\Container\Attributes\Singleton;

/** 运营后台会员查询。 */
#[Singleton]
final class PlatformMemberService
{
    /** 使用已保存的会员账户。 */
    public function __construct(private UserRepository $users, private YabandRemitMemberRepository $kycMembers) {}

    /** 按编号、姓名或邮箱查询会员，返回后台列表字段。 */
    public function page(string $keyword, int $page): Result
    {
        $rows = $this->users->memberPage($keyword, $page);
        $links = $this->kycMembers->forMembers($rows->getCollection()->modelKeys());
        $items = array_map(static function (User $user) use ($links): array {
            $link = $links->get($user->id);
            $generatedEmail = str_ends_with($user->email, '@mobile.invalid')
                || preg_match('/^test-sms-[0-9a-f]{64}@members\.invalid$/D', $user->email) === 1;

            return [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $generatedEmail ? null : $user->email,
                'masked_phone' => $user->phone_e164 === null ? null : '+34 ••••• '.substr($user->phone_e164, -4),
                'kyc_registered' => $link?->uid !== null,
                'kyc_code' => $link?->statuses['kyc_status_code'] ?? null,
                'created_at' => $user->created_at?->toISOString(),
            ];
        }, $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }
}

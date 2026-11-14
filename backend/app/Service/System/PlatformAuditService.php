<?php

namespace App\Service\System;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Repository\System\PlatformAuditRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
final class PlatformAuditService
{
    public const ACTIONS = ['rules_saved', 'role_changed', 'coupon_issued', 'coupon_revoked', 'notice_sent', 'reconciliation_imported', 'reconciliation_reviewed'];

    public function __construct(private PlatformAuditRepository $audit) {}

    /** 提供按时间排序的操作索引，详细变更继续从对应业务模块查看。 */
    public function page(int $page, ?string $action): Result
    {
        $result = $this->audit->page($page, $action);
        $list = array_map(static fn (object $record) => [
            'action' => $record->action,
            'reference_id' => (string) $record->reference_id,
            'operator_id' => $record->operator_id === null ? null : (string) $record->operator_id,
            'operator_name' => $record->operator_name,
            'occurred_at' => Carbon::parse($record->occurred_at)->toISOString(),
        ], $result->items());

        return Result::success(PaginationPayload::from($list, $result->total(), $page, $result->perPage())->toArray());
    }
}

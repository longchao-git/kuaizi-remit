<?php

namespace App\Repository\System;

use App\Model\Notification\MemberNotification;
use App\Model\Remittance\RemittanceCoupon;
use App\Model\Remittance\RemittanceRuleRevision;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** @extends AbstractRepository<RemittanceRuleRevision> */
final class PlatformAuditRepository extends AbstractRepository
{
    protected function model(): RemittanceRuleRevision
    {
        return new RemittanceRuleRevision;
    }

    public function page(int $page, ?string $action): LengthAwarePaginator
    {
        $rules = $this->query()->toBase()
            ->selectRaw('? AS action, id AS reference_id, staff_id AS operator_id, staff_name AS operator_name, created_at AS occurred_at', ['rules_saved']);
        $roles = DB::table('platform_role_audits')
            ->selectRaw('? AS action, id AS reference_id, operator_id, operator_name, created_at AS occurred_at', ['role_changed']);
        $couponsIssued = $this->couponEvents('coupon_issued', 'issued_by', 'created_at');
        $couponsRevoked = $this->couponEvents('coupon_revoked', 'revoked_by', 'revoked_at')->whereNotNull('coupons.revoked_at');
        $notifications = MemberNotification::query()->toBase()->from('member_notifications as notices')
            ->leftJoin('users', 'users.id', '=', 'notices.sender_id')
            ->selectRaw('? AS action, notices.id AS reference_id, notices.sender_id AS operator_id, users.name AS operator_name, notices.created_at AS occurred_at', ['notice_sent']);

        $imports = DB::table('remittance_reconciliation_batches as batches')
            ->leftJoin('users', 'users.id', '=', 'batches.imported_by')
            ->selectRaw('? AS action, batches.id AS reference_id, batches.imported_by AS operator_id, users.name AS operator_name, batches.created_at AS occurred_at', ['reconciliation_imported']);
        $reviews = DB::table('remittance_reconciliation_lines as lines')
            ->leftJoin('users', 'users.id', '=', 'lines.reviewed_by')->whereNotNull('lines.reviewed_at')
            ->selectRaw('? AS action, lines.id AS reference_id, lines.reviewed_by AS operator_id, users.name AS operator_name, lines.reviewed_at AS occurred_at', ['reconciliation_reviewed']);
        $timeline = DB::query()->fromSub(
            $rules->unionAll($roles)->unionAll($couponsIssued)->unionAll($couponsRevoked)
                ->unionAll($notifications)->unionAll($imports)->unionAll($reviews),
            'activity',
        );
        if ($action !== null) {
            $timeline->where('action', $action);
        }

        return $timeline->orderByDesc('occurred_at')->orderBy('action')->orderByDesc('reference_id')->paginate(20, page: $page);
    }

    private function couponEvents(string $action, string $actorColumn, string $timeColumn): Builder
    {
        return RemittanceCoupon::query()->toBase()->from('remittance_coupons as coupons')
            ->leftJoin('users', 'users.id', '=', 'coupons.'.$actorColumn)
            ->selectRaw("? AS action, coupons.id AS reference_id, coupons.$actorColumn AS operator_id, users.name AS operator_name, coupons.$timeColumn AS occurred_at", [$action]);
    }
}

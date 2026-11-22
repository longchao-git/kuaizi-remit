<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\RemittanceOrder;
use App\Repository\AbstractRepository;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** @extends AbstractRepository<RemittanceOrder> */
final class RemittanceOrderRepository extends AbstractRepository
{
    private const STAFF_STATES = [
        'pending_payment' => "refund_code = '00' AND income_code IN ('01', '02', '03', '04')",
        'processing' => "refund_code = '00' AND income_code = '00' AND outgoing_code NOT IN ('03', '05', '06', '07')",
        'paid_out' => "refund_code = '00' AND income_code = '00' AND outgoing_code = '05'",
        'refunding' => "refund_code IN ('01', '02', '03')",
        'refunded' => "refund_code = '04'",
        'exception' => "refund_code IN ('05', '06') OR (refund_code = '00' AND (income_code IN ('05', '06', '07', '08') OR (income_code = '00' AND outgoing_code IN ('03', '06', '07'))))",
    ];

    protected function model(): RemittanceOrder
    {
        return new RemittanceOrder;
    }

    public function page(int $page, ?int $member, ?string $reference, array $filters = []): LengthAwarePaginator
    {
        $status = self::STAFF_STATES[$filters['status'] ?? ''] ?? null;

        return $this->filtered($member, $reference, $filters)
            ->when($status !== null, fn ($query) => $query->whereRaw("($status)"))
            ->orderByDesc('id')->paginate(20, page: $page);
    }

    public function staffTotals(?int $member, ?string $reference, array $filters): array
    {
        $query = $this->filtered($member, $reference, $filters)->toBase()->selectRaw('COUNT(*) AS total');
        foreach (self::STAFF_STATES as $state => $condition) {
            $query->selectRaw("COALESCE(SUM(CASE WHEN ($condition) THEN 1 ELSE 0 END), 0) AS $state");
        }

        return array_map('intval', (array) $query->first());
    }

    private function filtered(?int $member, ?string $reference, array $filters): Builder
    {
        $payment = $filters['payment_method'] ?? null;
        $payout = $filters['payout_method'] ?? null;

        return $this->query()
            ->when($member !== null, fn ($query) => $query->where('member_id', $member))
            ->when($reference !== null, fn ($query) => $query->where('reference', $reference))
            ->when($payment !== null, fn ($query) => $query->where('quote_snapshot->payment_method', $payment))
            ->when($payout !== null, fn ($query) => $query->where('quote_snapshot->payout_method', $payout));
    }

    public function find(string $reference, ?int $member): ?RemittanceOrder
    {
        return $this->query()->where('reference', $reference)
            ->when($member !== null, fn ($query) => $query->where('member_id', $member))
            ->first();
    }

    public function reserve(int $member, string $quote, array $snapshot, bool $whiteLabel, string $hash): RemittanceOrder
    {
        return $this->query()->createOrFirst(['quote_reference' => $quote], [
            'reference' => (string) Str::uuid(), 'member_id' => $member, 'white_label' => $whiteLabel, 'quote_snapshot' => $snapshot,
            'principal_minor' => $snapshot['principal_minor'], 'payment_request_id' => (string) Str::uuid(), 'payment_request_hash' => $hash,
            'income_code' => '02', 'outgoing_code' => '01', 'refund_code' => '00',
        ]);
    }

    public function getForQuote(int $member, string $quote): ?RemittanceOrder
    {
        return $this->query()->where('member_id', $member)->where('quote_reference', $quote)->first();
    }

    /** 已付款或尚可能入账的订单均影响首单资格。 */
    public function hasPaidOrPending(int $member): bool
    {
        return $this->query()->where('member_id', $member)
            ->where(fn ($query) => $query->whereNotIn('income_code', ['05', '06', '07', '08'])->orWhere('refund_code', '!=', '00'))
            ->exists();
    }

    public function hasUnsettled(int $member): bool
    {
        return $this->unsettled($this->query()->where('member_id', $member))->exists();
    }

    /** 未结清的状态口径与注销检查一致，包含退款失败及下发失败后待退款的订单。 */
    private function unsettled(Builder $query): Builder
    {
        return $query->where(function ($query) {
            $query->whereNotIn('refund_code', ['00', '04'])
                ->orWhere(fn ($order) => $order->where('refund_code', '00')
                    ->whereNotIn('income_code', ['05', '06', '07', '08'])
                    ->where(fn ($state) => $state->where('income_code', '!=', '00')->orWhere('outgoing_code', '!=', '05')));
        });
    }

    /** 按最久未尝试的顺序选择待同步订单，失败记录同样参与间隔和排序。 */
    public function dueForSync(DateTimeInterface $before, int $limit): Collection
    {
        return $this->syncDueQuery($before)
            ->orderByRaw('COALESCE(provider_sync_attempted_at, provider_checked_at, created_at) ASC')
            ->orderBy('id')->limit($limit)->get(['reference']);
    }

    /** 获取单订单锁后再次确认，避免批次选中后回调或其他进程已经更新订单。 */
    public function isSyncDue(RemittanceOrder $order, DateTimeInterface $before): bool
    {
        return $this->syncDueQuery($before)->whereKey($order->getKey())->exists();
    }

    private function syncDueQuery(DateTimeInterface $before): Builder
    {
        return $this->unsettled($this->query())->whereNotNull('provider_trade_id')->where('provider_trade_id', '!=', '')
            ->where('created_at', '<=', $before)
            ->where(fn ($query) => $query->whereNull('provider_sync_attempted_at')->orWhere('provider_sync_attempted_at', '<=', $before))
            ->where(fn ($query) => $query->whereNull('provider_checked_at')->orWhere('provider_checked_at', '<=', $before));
    }

    /** 在持有单订单锁时记录尝试，不把请求失败伪装成状态已核实。 */
    public function markSyncAttempt(RemittanceOrder $order): void
    {
        $order->forceFill(['provider_sync_attempted_at' => now()])->saveOrFail();
    }

    /**
     * 按调用方给出的当地日界归集，按较早的日界先匹配。
     *
     * @param  array<string, array{DateTimeInterface, DateTimeInterface}>  $days
     */
    public function dashboardDailyMetrics(array $days): Collection
    {
        if ($days === []) {
            return new Collection;
        }

        $bindings = [];
        foreach ($days as $date => [, $end]) {
            $bindings[] = $end;
            $bindings[] = $date;
        }
        $dayExpression = str_repeat('WHEN created_at < ? THEN ? ', count($days));

        return $this->query()->toBase()
            ->where('created_at', '>=', $days[array_key_first($days)][0])
            ->where('created_at', '<', $days[array_key_last($days)][1])
            ->selectRaw("CASE {$dayExpression}END AS report_date, COUNT(*) AS order_count,
                SUM(CASE WHEN income_code = '00' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN income_code = '00' THEN principal_minor ELSE 0 END) AS paid_principal_minor,
                SUM(CASE WHEN income_code = '00' AND outgoing_code = '05' AND refund_code = '00' THEN 1 ELSE 0 END) AS paid_out_count,
                SUM(CASE WHEN refund_code = '04' THEN 1 ELSE 0 END) AS refunded_count,
                SUM(CASE WHEN refund_code = '00' AND income_code IN ('05', '06', '07', '08') THEN 1 ELSE 0 END) AS payment_failed_count,
                SUM(CASE WHEN refund_code = '00' AND income_code = '00' AND outgoing_code IN ('03', '06', '07') THEN 1 ELSE 0 END) AS payout_failed_count,
                SUM(CASE WHEN refund_code IN ('05', '06') THEN 1 ELSE 0 END) AS refund_failed_count", $bindings)
            ->groupBy('report_date')->get()->keyBy('report_date');
    }

    public function dashboardRecent(DateTimeInterface $start, DateTimeInterface $end): Collection
    {
        return $this->query()->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get();
    }

    public function forReconciliation(array $references): Collection
    {
        $orders = new Collection;
        foreach (array_chunk($references, 500) as $chunk) {
            $orders = $orders->union($this->query()->whereIn('reference', $chunk)
                ->get(['reference', 'principal_minor', 'income_code', 'outgoing_code', 'refund_code'])->keyBy('reference'));
        }

        return $orders;
    }

    public function savePayment(RemittanceOrder $order, string $trade, ?string $url): void
    {
        $order->forceFill(['provider_trade_id' => $trade, 'payment_url' => $url])->saveOrFail();
    }

    public function saveProviderState(RemittanceOrder $order, string $income, string $outgoing, string $refund): void
    {
        $order->forceFill([
            'income_code' => $income,
            'outgoing_code' => $outgoing,
            'refund_code' => $refund,
            'provider_checked_at' => now(),
        ])->saveOrFail();
    }
}

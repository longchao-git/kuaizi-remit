<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\RemittanceOrder;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** @extends AbstractRepository<RemittanceOrder> */
final class RemittanceOrderRepository extends AbstractRepository
{
    protected function model(): RemittanceOrder
    {
        return new RemittanceOrder;
    }

    public function page(int $page, ?int $member, ?string $reference): LengthAwarePaginator
    {
        return $this->query()
            ->when($member !== null, fn ($query) => $query->where('member_id', $member))
            ->when($reference !== null, fn ($query) => $query->where('reference', $reference))
            ->orderByDesc('id')->paginate(20, page: $page);
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
        return $this->query()->where('member_id', $member)->where(function ($query) {
            $query->whereNotIn('refund_code', ['00', '04'])
                ->orWhere(fn ($order) => $order->where('refund_code', '00')
                    ->whereNotIn('income_code', ['05', '06', '07', '08'])
                    ->where(fn ($state) => $state->where('income_code', '!=', '00')->orWhere('outgoing_code', '!=', '05')));
        })->exists();
    }

    public function dashboardMetrics(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $row = $this->query()->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw("COUNT(*) AS order_count,
                SUM(CASE WHEN income_code = '00' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN income_code = '00' THEN principal_minor ELSE 0 END) AS paid_principal_minor,
                SUM(CASE WHEN income_code = '00' AND outgoing_code = '05' AND refund_code = '00' THEN 1 ELSE 0 END) AS paid_out_count,
                SUM(CASE WHEN refund_code = '04' THEN 1 ELSE 0 END) AS refunded_count")->firstOrFail();

        return array_map('intval', $row->getAttributes());
    }

    public function dashboardRecent(\DateTimeInterface $start, \DateTimeInterface $end): Collection
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

<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\RemittanceRuleRevision;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use RuntimeException;

/** @extends AbstractRepository<RemittanceRuleRevision> */
final class RemittanceRuleRevisionRepository extends AbstractRepository
{
    protected function model(): RemittanceRuleRevision
    {
        return new RemittanceRuleRevision;
    }

    /** 追加保存后的完整规则快照。 */
    public function append(int $staff, string $name, array $settings): int
    {
        $revision = $this->query()->create(['staff_id' => $staff, 'staff_name' => $name, 'settings' => $settings]);
        if (! $revision->wasRecentlyCreated) {
            throw new RuntimeException('未能保存汇款规则记录。');
        }

        return (int) $revision->getKey();
    }

    /** 最新版本优先。 */
    public function page(int $page): LengthAwarePaginator
    {
        return $this->query()->orderByDesc('id')->paginate(20, page: $page);
    }
}

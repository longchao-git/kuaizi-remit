<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\AgreementDraft;
use App\Repository\AbstractRepository;

/** @extends AbstractRepository<AgreementDraft> */
final class AgreementDraftRepository extends AbstractRepository
{
    protected function model(): AgreementDraft
    {
        return new AgreementDraft;
    }

    /** 读取单类协议的草稿，不参与公开协议查询。 */
    public function find(string $kind): ?AgreementDraft
    {
        return $this->query()->where('kind', $kind)->first();
    }

    /** 写入事务中锁定现有草稿，配合修订号避免覆盖其他编辑者。 */
    public function lock(string $kind): ?AgreementDraft
    {
        return $this->query()->where('kind', $kind)->lockForUpdate()->first();
    }

    /** 首次保存依靠种类唯一约束防止并发插入两份草稿。 */
    public function create(string $kind, array $attributes): AgreementDraft
    {
        return $this->query()->create(['kind' => $kind, ...$attributes]);
    }

    /** 在已锁定草稿上保存正文、操作人和新修订号。 */
    public function update(AgreementDraft $draft, array $attributes): AgreementDraft
    {
        $draft->fill($attributes)->save();

        return $draft->refresh();
    }

    /** 发布成功后只移除当前事务核对过的草稿。 */
    public function remove(AgreementDraft $draft): void
    {
        $draft->delete();
    }
}

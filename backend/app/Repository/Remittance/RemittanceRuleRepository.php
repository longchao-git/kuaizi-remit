<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\RemittanceRule;
use App\Repository\AbstractRepository;
use RuntimeException;

/** @extends AbstractRepository<RemittanceRule> */
final class RemittanceRuleRepository extends AbstractRepository
{
    protected function model(): RemittanceRule
    {
        return new RemittanceRule;
    }

    public function settings(string $key): ?array
    {
        return $this->findByKey($key)?->settings;
    }

    public function findByKey(string $key, bool $lock = false): ?RemittanceRule
    {
        return $this->query()->where('key', $key)->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function save(string $key, array $settings, ?int $revisionId = null): void
    {
        $timestamp = now();
        $updated = $this->query()->upsert([[
            'key' => $key,
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            'revision_id' => $revisionId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]], ['key'], ['settings', 'revision_id', 'updated_at']);

        if ($updated < 1) {
            $existing = $this->findByKey($key);
            if ($existing?->settings !== $settings || $existing->revision_id !== $revisionId) {
                throw new RuntimeException('未能保存汇款规则。');
            }
        }
    }
}

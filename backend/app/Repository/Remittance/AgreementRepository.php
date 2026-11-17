<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\AgreementVersion;
use App\Repository\AbstractRepository;

/** @extends AbstractRepository<AgreementVersion> */
final class AgreementRepository extends AbstractRepository
{
    protected function model(): AgreementVersion
    {
        return new AgreementVersion;
    }

    public function latest(string $kind): ?AgreementVersion
    {
        return $this->query()->where('kind', $kind)->latest('id')->first();
    }

    public function publish(string $kind, string $body, int $staffId): AgreementVersion
    {
        return $this->query()->create([
            'kind' => $kind,
            'body' => $body,
            'published_by' => $staffId,
            'published_at' => now(),
        ]);
    }
}

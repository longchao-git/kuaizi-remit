<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\RemittanceCouponTemplate;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** @extends AbstractRepository<RemittanceCouponTemplate> */
final class RemittanceCouponTemplateRepository extends AbstractRepository
{
    protected function model(): RemittanceCouponTemplate
    {
        return new RemittanceCouponTemplate;
    }

    public function page(int $page, bool $enabledOnly): LengthAwarePaginator
    {
        $query = $this->query()->orderByDesc('id');
        if ($enabledOnly) {
            $query->where('enabled', true);
        }

        return $query->paginate(20, page: $page);
    }

    public function find(int $id, bool $lock = false): ?RemittanceCouponTemplate
    {
        $query = $this->query();
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($id);
    }

    public function create(array $input): RemittanceCouponTemplate
    {
        return $this->query()->create($input);
    }

    public function update(RemittanceCouponTemplate $template, array $input): RemittanceCouponTemplate
    {
        $template->fill($input)->saveOrFail();

        return $template;
    }
}

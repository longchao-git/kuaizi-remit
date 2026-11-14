<?php

namespace App\Repository;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @template TModel of Model */
abstract class AbstractRepository
{
    /** @return TModel */
    abstract protected function model(): Model;

    /** @return Builder<TModel> */
    protected function query(): Builder
    {
        return $this->model()->newQuery();
    }
}

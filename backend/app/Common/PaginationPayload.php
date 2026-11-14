<?php

namespace App\Common;

use InvalidArgumentException;

final readonly class PaginationPayload
{
    private function __construct(
        private array $list,
        private int $total,
        private int $page,
        private int $pageSize,
    ) {}

    /** 从查询结果和当前分页参数构造分页数据。 */
    public static function from(array $list, int $total, int $page, int $pageSize): self
    {
        if ($total < 0 || $page < 1 || $pageSize < 1) {
            throw new InvalidArgumentException('分页参数不合法。');
        }

        return new self(array_values($list), $total, $page, $pageSize);
    }

    public function toArray(): array
    {
        return [
            'list' => $this->list,
            'total' => $this->total,
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'total_pages' => intdiv($this->total, $this->pageSize) + (int) ($this->total % $this->pageSize !== 0),
        ];
    }
}

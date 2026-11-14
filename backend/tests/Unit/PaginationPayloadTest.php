<?php

namespace Tests\Unit;

use App\Common\PaginationPayload;
use PHPUnit\Framework\TestCase;

class PaginationPayloadTest extends TestCase
{
    public function test_partial_last_page_keeps_a_json_array_and_rounds_up_the_page_count(): void
    {
        $this->assertSame([
            'list' => [['id' => 21]],
            'total' => 21,
            'page' => 3,
            'page_size' => 10,
            'total_pages' => 3,
        ], PaginationPayload::from([20 => ['id' => 21]], 21, 3, 10)->toArray());
    }

    public function test_empty_search_has_zero_total_pages(): void
    {
        $payload = PaginationPayload::from([], 0, 1, 10)->toArray();

        $this->assertSame([], $payload['list']);
        $this->assertSame(0, $payload['total_pages']);
    }
}

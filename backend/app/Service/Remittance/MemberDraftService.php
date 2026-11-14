<?php

namespace App\Service\Remittance;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Remittance\RemittanceQuoteRecord;
use App\Repository\Remittance\RemittanceQuoteRecordRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;

#[Singleton]
final class MemberDraftService
{
    public function __construct(
        private RemittanceQuoteRecordRepository $records,
        private DatabaseManager $database,
    ) {}

    public function page(int $member, int $page): Result
    {
        $rows = $this->records->drafts($member, $page);
        $items = array_map($this->format(...), $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }

    public function save(int $member, string $reference): Result
    {
        $draft = $this->database->transaction(fn () => $this->records->saveDraft($member, $reference), attempts: 3);

        return $draft === null
            ? Result::fail('找不到可保存的试算记录', 404)
            : Result::success($this->format($draft));
    }

    public function remove(int $member, string $reference): Result
    {
        $this->records->removeDraft($member, $reference);

        return Result::success();
    }

    private function format(RemittanceQuoteRecord $record): array
    {
        $snapshot = $record->snapshot;

        return [
            'record_no' => $record->reference,
            'principal_minor' => $snapshot['principal_minor'],
            'payment_method' => $snapshot['payment_method'],
            'payout_method' => $snapshot['payout_method'],
            'draft_saved_at' => $record->draft_saved_at->toISOString(),
        ];
    }
}

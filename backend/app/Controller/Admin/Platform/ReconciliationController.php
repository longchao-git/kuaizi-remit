<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Controller\AbstractController;
use App\Service\Remittance\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReconciliationController extends AbstractController
{
    public function __construct(private ReconciliationService $reconciliation) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'settlement_date' => ['nullable', 'date_format:Y-m-d'],
            'order_reference' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:pending,reviewed'],
        ]);

        return $this->respond($this->reconciliation->page(
            (int) ($input['page'] ?? 1), $input['settlement_date'] ?? null, $input['order_reference'] ?? null, $input['status'] ?? null,
        ));
    }

    public function import(Request $request): JsonResponse
    {
        $input = $request->validate([
            'settlement_date' => ['required', 'date_format:Y-m-d'],
            'file' => ['required', 'file', 'extensions:csv', 'max:1024'],
        ]);
        $file = $input['file'];
        $filename = mb_substr($file->getClientOriginalName(), 0, 180);

        return $this->respond($this->reconciliation->import($file->get(), $input['settlement_date'], $filename, (int) $request->user()->getKey()));
    }

    public function lines(Request $request, int $batch): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'filter' => ['sometimes', 'in:all,differences,pending'],
        ]);

        return $this->respond($this->reconciliation->lines($batch, (int) ($input['page'] ?? 1), $input['filter'] ?? 'all'));
    }

    public function review(Request $request, int $batch, int $line): JsonResponse
    {
        $input = $request->validate(['note' => ['required', 'string', 'max:300']], ['note.required' => '请填写复核结论']);

        return $this->respond($this->reconciliation->review($batch, $line, (int) $request->user()->getKey(), $input['note']));
    }

    public function export(int $batch): JsonResponse|StreamedResponse
    {
        $result = $this->reconciliation->export($batch);
        if ($result->isFail()) {
            return $this->respond($result);
        }

        return response()->streamDownload(static function () use ($result): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['order_id', 'imported_eur', 'expected_eur', 'imported_status', 'expected_status', 'result', 'reviewed_by', 'reviewed_at', 'review_note'], escape: '');
            foreach ($result->getData() as $row) {
                $note = $row['review_note'] ?? '';
                if (preg_match('/^[\s\p{Cf}]*[=+@-]/u', $note) === 1) {
                    $note = "'".$note;
                }
                fputcsv($stream, [
                    $row['order_reference'], self::decimal($row['imported_minor']), self::decimal($row['expected_minor']),
                    $row['imported_status'], $row['expected_status'], $row['result'], $row['reviewed_by'], $row['reviewed_at'], $note,
                ], escape: '');
            }
            fclose($stream);
        }, "reconciliation-{$batch}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private static function decimal(?int $minor): string
    {
        return $minor === null ? '' : sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    private function respond(Result $result): JsonResponse
    {
        return $result->isSuccess() ? $this->success($result->getData(), $result->getMsg()) : ResponseResult::json($result, $result->getCode());
    }
}

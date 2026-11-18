<?php

namespace App\Service\Remittance\Yaband;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Remittance\YabandMaterialRequest;
use App\Repository\Remittance\RemittanceOrderRepository;
use App\Repository\Remittance\YabandMaterialRequestRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

#[Singleton]
final class MemberMaterialService
{
    public function __construct(
        private YabandMaterialRequestRepository $tasks,
        private RemittanceOrderRepository $orders,
        private YabandRemitMemberRepository $members,
        private YabandRemitClient $yaband,
    ) {}

    public function page(int $page, ?int $member): Result
    {
        $rows = $this->tasks->page($page, $member);
        $items = array_map(fn (YabandMaterialRequest $task) => $this->present($task, $member === null), $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }

    /** 运营按已确认的雅本问题编号登记任务，会员端不能指定 qno。 */
    public function register(array $input): Result
    {
        $member = (int) $input['member_id'];
        $order = isset($input['order_reference']) ? strtolower($input['order_reference']) : null;
        if ($this->members->findFor($member)?->uid === null || ($order !== null && $this->orders->find($order, $member) === null)) {
            return Result::fail('找不到已关联雅本的会员或本人订单', 404);
        }

        $deadline = Carbon::createFromTimestampUTC($input['deadline_at']);
        if ($deadline->isPast()) {
            return Result::fail('补件截止时间必须晚于当前时间', 422);
        }
        $task = $this->tasks->register($input['question_id'], [
            'member_id' => $member, 'order_reference' => $order, 'instruction' => $input['instruction'], 'deadline_at' => $deadline,
        ]);
        if ($task->member_id !== $member || $task->order_reference !== $order
            || $task->instruction !== $input['instruction'] || $task->deadline_at->timestamp !== $deadline->timestamp) {
            return Result::fail('雅本问题编号已用于其他补件任务', 409);
        }

        return Result::success($this->present($task, true));
    }

    /** 附件直传雅本，业务数据库仅保留请求编号、摘要和结果。 */
    public function submit(int $member, string $reference, string $reply, ?UploadedFile $file): Result
    {
        $reference = strtolower($reference);
        $lock = Cache::lock('yaband-remit:materials:'.$reference, 45);
        if (! $lock->get()) {
            return Result::fail('补件正在处理，请稍后查询', 409);
        }

        try {
            $task = $this->tasks->find($reference, $member);
            if ($task === null) {
                return Result::fail('补件任务不存在', 404);
            }
            $encoded = $file === null ? '' : base64_encode($file->get());
            $media = match ($file?->getMimeType()) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'application/pdf' => 'pdf',
                default => null,
            };
            if ($file !== null && ($media === null || $encoded === '' || strlen($encoded) > 100000)) {
                return Result::fail('请选择不超过 75,000 字节的 PDF、JPG 或 PNG 文件', 422);
            }
            $hash = hash_hmac('sha256', json_encode([$reply, $media, $encoded], JSON_THROW_ON_ERROR), (string) config('app.key'));
            if ($task->status === '6002' && hash_equals($task->submission_hash ?? '', $hash)) {
                return Result::success($this->present($task));
            }
            if ($task->status !== '6001' || $task->deadline_at->isPast()) {
                return Result::fail('补件任务已关闭或过期', 409);
            }
            if (! app()->bound(YabandRemitSigner::class)) {
                return Result::fail('雅本补件暂未开放', 503);
            }
            if ($task->submission_id === null) {
                $this->tasks->reserve($task, $hash);
            } elseif (! hash_equals($task->submission_hash, $hash)) {
                return Result::fail('上次提交结果未确认，请使用原回复和附件重试', 409);
            }

            $documents = $file === null ? [] : [
                ['doc_id' => $task->submission_id, 'media_type' => $media, 'attachment' => $encoded],
            ];
            try {
                $response = $this->yaband->submitMaterials($task->submission_id, $task->question_id, $reply, $documents);
            } catch (YabandException $error) {
                $status = match ($error->upstreamCode) {
                    -1032 => '6003',
                    -1033 => '6002',
                    default => throw $error,
                };
                $this->tasks->saveStatus($task, $status, submissionConfirmed: false);

                return Result::fail($status === '6003' ? '雅本补件任务已过期' : '雅本补件任务已完成，请刷新查看', 409);
            }
            $status = $response['qno_status_code'] ?? null;
            if (($response['qno'] ?? null) !== $task->question_id || ! in_array($status, ['6002', '6003'], true)) {
                return Result::fail('补件结果待确认，请使用原资料重试', 502);
            }
            $this->tasks->saveStatus($task, $status, submissionConfirmed: $status === '6002');

            return $status === '6002' ? Result::success($this->present($task)) : Result::fail('雅本补件任务已过期', 409);
        } catch (YabandException|LockTimeoutException) {
            return Result::fail('雅本补件结果未确认，请使用原资料重试', 503);
        } finally {
            $lock->release();
        }
    }

    private function present(YabandMaterialRequest $task, bool $staff = false): array
    {
        $status = $task->status === '6001' && $task->deadline_at->isPast() ? '6003' : $task->status;
        $item = [
            'reference' => $task->reference,
            'order_reference' => $task->order_reference,
            'instruction' => $task->instruction,
            'deadline_at' => $task->deadline_at->toISOString(),
            'status' => $status,
            'can_submit' => $status === '6001',
        ];

        return $staff ? [...$item, 'question_id' => $task->question_id, 'member_id' => (string) $task->member_id] : $item;
    }
}

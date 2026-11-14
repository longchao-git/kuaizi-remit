<?php

namespace App\Service\Support;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Support\SupportTicket;
use App\Repository\Remittance\RemittanceOrderRepository;
use App\Repository\Support\SupportTicketRepository;
use App\Service\Notification\MemberNotificationService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

#[Singleton]
final class SupportTicketService
{
    public const STATUSES = ['open', 'processing', 'resolved'];

    public function __construct(
        private SupportTicketRepository $tickets,
        private RemittanceOrderRepository $orders,
        private MemberNotificationService $notices,
        private DatabaseManager $database,
    ) {}

    public function page(int $page, ?int $member, ?string $status): Result
    {
        $rows = $this->tickets->page($page, $member, $status);
        $items = array_map(fn (SupportTicket $ticket) => $this->format($ticket, $member === null), $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }

    public function show(int $id, ?int $member): Result
    {
        $ticket = $this->tickets->find($id, $member);
        if ($ticket === null) {
            return Result::fail('工单不存在', 404);
        }

        return Result::success($this->detail($ticket, $member === null));
    }

    public function create(int $member, array $input): Result
    {
        $reference = isset($input['order_reference']) ? strtolower($input['order_reference']) : null;
        if ($reference !== null && $this->orders->find($reference, $member) === null) {
            return Result::fail('订单不存在', 404);
        }

        $ticket = $this->tickets->createOnce($member, strtolower($input['client_id']), $input['subject'], $input['body'], $reference);
        if ($ticket->subject !== $input['subject'] || $ticket->body !== $input['body'] || $ticket->order_reference !== $reference) {
            return Result::fail('此提交编号已用于其他工单', 409);
        }

        return Result::success($this->format($ticket, false), '已提交工单');
    }

    public function update(int $id, int $staff, array $input): Result
    {
        $notifyMember = null;
        $result = $this->database->transaction(function () use ($id, $staff, $input, &$notifyMember): Result {
            $notifyMember = null;
            $ticket = $this->tickets->find($id, lock: true);
            if ($ticket === null) {
                return Result::fail('工单不存在', 404);
            }

            $clientId = strtolower($input['client_id']);
            $previous = $this->tickets->findUpdate($id, $clientId);
            if ($previous !== null) {
                if ((int) $previous->staff_id !== $staff || $previous->reply !== $input['reply']
                    || $previous->status !== $input['status'] || (int) $previous->version !== $input['version'] + 1) {
                    return Result::fail('此提交编号已用于其他处理记录', 409);
                }

                return Result::success($this->detail($ticket, true));
            }

            if ($ticket->version !== $input['version']) {
                return Result::fail('工单已更新，请刷新后再提交', 409);
            }
            if ($ticket->status === 'resolved' || $input['status'] === 'open') {
                return Result::fail('当前工单状态不允许此操作', 409);
            }

            $this->tickets->update($ticket, $staff, $clientId, $input['status'], $input['reply']);
            $statusLabel = $ticket->status === 'resolved' ? '已解决' : '处理中';
            $notice = $this->notices->send($ticket->member_id, $staff, [
                'request_id' => (string) Str::uuid(),
                'title' => '客服工单 #'.$ticket->id.' · '.$statusLabel,
                'body' => '客服已回复，请在「客服工单」查看详情。',
                'support_ticket_id' => $ticket->id,
            ], broadcast: false);
            if ($notice->isFail()) {
                throw new RuntimeException('未能保存工单通知。');
            }
            $notifyMember = $ticket->member_id;

            return Result::success($this->detail($ticket, true));
        }, attempts: 3);
        if ($notifyMember !== null) {
            $this->notices->refreshInbox($notifyMember);
        }

        return $result;
    }

    private function format(SupportTicket $ticket, bool $staff): array
    {
        $data = [
            'id' => (string) $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'order_reference' => $ticket->order_reference,
            'version' => $ticket->version ?? 0,
            'created_at' => $ticket->created_at->toISOString(),
        ];
        if ($staff) {
            $data['member_id'] = (string) $ticket->member_id;
        }

        return $data;
    }

    private function detail(SupportTicket $ticket, bool $staff): array
    {
        return [
            ...$this->format($ticket, $staff),
            'body' => $ticket->body,
            'updates' => $this->tickets->updates($ticket->id)->map(static fn (object $update) => [
                'version' => (int) $update->version,
                'status' => $update->status,
                'reply' => $update->reply,
                'created_at' => Carbon::parse($update->created_at)->toISOString(),
            ])->all(),
        ];
    }
}

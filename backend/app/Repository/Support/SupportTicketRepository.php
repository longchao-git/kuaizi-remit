<?php

namespace App\Repository\Support;

use App\Model\Support\SupportTicket;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** @extends AbstractRepository<SupportTicket> */
final class SupportTicketRepository extends AbstractRepository
{
    protected function model(): SupportTicket
    {
        return new SupportTicket;
    }

    public function page(int $page, ?int $member, ?string $status): LengthAwarePaginator
    {
        $query = $this->query()->orderByDesc('id');
        if ($member !== null) {
            $query->where('member_id', $member);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate(20, page: $page);
    }

    public function find(int $id, ?int $member = null, bool $lock = false): ?SupportTicket
    {
        $query = $this->query();
        if ($member !== null) {
            $query->where('member_id', $member);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($id);
    }

    public function createOnce(int $member, string $clientId, string $subject, string $body, ?string $reference): SupportTicket
    {
        return $this->query()->createOrFirst(['member_id' => $member, 'client_id' => $clientId], [
            'subject' => $subject, 'body' => $body, 'status' => 'open', 'order_reference' => $reference,
        ]);
    }

    public function updates(int $ticket): Collection
    {
        return DB::table('support_ticket_updates')->where('ticket_id', $ticket)->orderBy('version')
            ->get(['version', 'status', 'reply', 'created_at']);
    }

    public function findUpdate(int $ticket, string $clientId): ?object
    {
        return DB::table('support_ticket_updates')->where('ticket_id', $ticket)->where('client_id', $clientId)->first();
    }

    public function update(SupportTicket $ticket, int $staff, string $clientId, string $status, string $reply): void
    {
        $version = $ticket->version + 1;
        $ticket->forceFill(['status' => $status, 'version' => $version])->saveOrFail();
        DB::table('support_ticket_updates')->insert([
            'ticket_id' => $ticket->id, 'staff_id' => $staff, 'client_id' => $clientId,
            'version' => $version, 'status' => $status, 'reply' => $reply, 'created_at' => now(),
        ]);
    }
}

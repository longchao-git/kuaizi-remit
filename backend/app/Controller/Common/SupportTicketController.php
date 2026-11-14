<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Controller\AbstractController;
use App\Service\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SupportTicketController extends AbstractController
{
    public function __construct(private SupportTicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'status' => ['nullable', Rule::in(SupportTicketService::STATUSES)],
        ]);

        return $this->respond($this->tickets->page((int) ($filters['page'] ?? 1), $this->member($request), $filters['status'] ?? null));
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return $this->respond($this->tickets->show($ticket, $this->member($request)));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->validate([
            'client_id' => ['required', 'uuid'], 'subject' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:500'],
            'order_reference' => ['nullable', 'uuid'],
        ]);

        return $this->respond($this->tickets->create((int) $request->user()->getKey(), $input));
    }

    public function update(Request $request, int $ticket): JsonResponse
    {
        $input = $request->validate([
            'client_id' => ['required', 'uuid'],
            'version' => ['required', 'integer:strict', 'between:0,2147483646'],
            'status' => ['required', 'in:processing,resolved'],
            'reply' => ['required', 'string', 'max:500'],
        ]);

        return $this->respond($this->tickets->update($ticket, (int) $request->user()->getKey(), $input));
    }

    private function member(Request $request): ?int
    {
        return $request->routeIs('support.tickets.member.*') ? (int) $request->user()->getKey() : null;
    }

    private function respond(Result $result): JsonResponse
    {
        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : ResponseResult::json($result, $result->getCode());
    }
}

<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Notification\PushReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PushReminderController extends AbstractController
{
    public function __construct(private PushReminderService $reminders) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return $this->success($this->reminders->plans((int) ($input['page'] ?? 1))->getData());
    }

    public function records(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'plan_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->success($this->reminders->sends((int) ($input['page'] ?? 1),
            isset($input['plan_id']) ? (int) $input['plan_id'] : null)->getData());
    }

    public function save(Request $request, ?int $plan = null): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:500'],
            'send_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
        ]);
        $result = $this->reminders->save($plan, $input);

        return ResponseResult::json($result, $result->isSuccess() ? 200 : $result->getCode());
    }

    public function status(Request $request, int $plan): JsonResponse
    {
        $input = $request->validate(['active' => ['required', 'boolean:strict']]);
        $result = $this->reminders->setActive($plan, $input['active']);

        return ResponseResult::json($result, $result->isSuccess() ? 200 : $result->getCode());
    }
}

<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiNotificationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Notifications (`cipi notification`, Cipi CLI ≥ 5.12.0): every channel and
 * every trigger. All synchronous.
 *
 * Reading needs `notifications-view` (or `smtp-view`, which tokens made for
 * the SMTP-only page already carry); changing needs `notifications-manage`
 * (or `smtp-manage`). The /smtp routes stay as they were.
 */
class NotificationController extends Controller
{
    public function __construct(
        protected CipiNotificationsService $notifications,
    ) {}

    public function show(): JsonResponse
    {
        return $this->respond(fn () => $this->notifications->status());
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['type' => 'required|' . CipiNotificationsService::CHANNEL_RULES['type']] + CipiNotificationsService::CHANNEL_RULES);
        if ($err = $this->notifications->channelError($input, null)) {
            return response()->json(['error' => $err], 422);
        }
        $id = $input['type'] === 'smtp' ? 'smtp' : $input['id'];
        try {
            if ($this->notifications->channel($id) !== null) {
                return response()->json(['error' => "Channel '{$id}' already exists — PUT /api/notifications/channels/{$id} to change it"], 409);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return $this->respond(fn () => $this->notifications->add($input), 422, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($r = $this->badId($id)) {
            return $r;
        }
        $input = $request->validate(CipiNotificationsService::CHANNEL_RULES);
        unset($input['id']);
        try {
            $current = $this->notifications->channel($id);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        if ($current === null) {
            return response()->json(['error' => "Channel '{$id}' not found"], 404);
        }
        if ($err = $this->notifications->channelError($input, $current)) {
            return response()->json(['error' => $err], 422);
        }
        unset($input['type']);
        if ($input === []) {
            return response()->json(['error' => 'Nothing to change'], 422);
        }

        return $this->respond(fn () => $this->notifications->update($id, $current['type'], $input), 422);
    }

    public function destroy(string $id): JsonResponse
    {
        if ($r = $this->badId($id)) {
            return $r;
        }

        return $this->respond(function () use ($id) {
            $this->notifications->remove($id);

            return $this->notifications->status();
        }, 422);
    }

    public function enable(string $id): JsonResponse
    {
        return $this->toggle($id, true);
    }

    public function disable(string $id): JsonResponse
    {
        return $this->toggle($id, false);
    }

    /** 200 {ok: true, message} when sent; 422 {error} when the channel refused it. "all" tests every enabled channel. */
    public function test(string $id): JsonResponse
    {
        if ($id !== 'all' && ($r = $this->badId($id))) {
            return $r;
        }
        try {
            $result = $this->notifications->test($id);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return $result['ok']
            ? response()->json(['data' => $result], 200)
            : response()->json(['error' => $result['message'], 'data' => $result], 422);
    }

    public function triggers(): JsonResponse
    {
        return $this->respond(fn () => $this->notifications->triggers());
    }

    public function updateTriggers(Request $request): JsonResponse
    {
        $input = $request->validate(CipiNotificationsService::TRIGGERS_RULES);
        if ($err = $this->notifications->triggersError($input)) {
            return response()->json(['error' => $err], 422);
        }

        return $this->respond(fn () => $this->notifications->updateTriggers($input), 422);
    }

    public function resetTriggers(): JsonResponse
    {
        return $this->respond(fn () => $this->notifications->resetTriggers(), 422);
    }

    protected function toggle(string $id, bool $enabled): JsonResponse
    {
        if ($r = $this->badId($id)) {
            return $r;
        }

        return $this->respond(fn () => $this->notifications->setEnabled($id, $enabled), 422);
    }

    protected function badId(string $id): ?JsonResponse
    {
        return preg_match(CipiNotificationsService::ID_PATTERN, $id) === 1
            ? null
            : response()->json(['error' => 'Invalid channel id'], 422);
    }

    /** 503 for reads and an older core; $writeStatus when the CLI refused a change. */
    protected function respond(callable $fn, int $writeStatus = 503, int $ok = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()], $ok);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'needs Cipi') ? 503 : $writeStatus;

            return response()->json(['error' => $e->getMessage()], $status);
        }
    }
}

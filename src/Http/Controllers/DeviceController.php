<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $tokenId = $this->tokenId($request);
        if ($tokenId === null) {
            return response()->json(['error' => 'No active token'], 401);
        }

        $devices = Device::query()
            ->where('token_id', $tokenId)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Device $d) => $this->present($d));

        return response()->json(['data' => $devices], 200);
    }

    public function create(Request $request): JsonResponse
    {
        $tokenId = $this->tokenId($request);
        if ($tokenId === null) {
            return response()->json(['error' => 'No active token'], 401);
        }

        $validated = $request->validate([
            'platform' => ['required', 'string', Rule::in(['ios', 'android', 'web'])],
            'push_token' => 'required|string|min:8|max:512',
            'device_name' => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:64',
            'os_version' => 'nullable|string|max:64',
            'notification_preferences' => 'nullable|array',
        ]);

        $device = Device::updateOrCreate(
            [
                'token_id' => $tokenId,
                'push_token' => $validated['push_token'],
            ],
            [
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'notification_preferences' => $validated['notification_preferences'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['data' => $this->present($device)], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tokenId = $this->tokenId($request);
        if ($tokenId === null) {
            return response()->json(['error' => 'No active token'], 401);
        }

        $device = Device::query()
            ->where('id', $id)
            ->where('token_id', $tokenId)
            ->first();

        if (! $device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        $validated = $request->validate([
            'device_name' => 'sometimes|nullable|string|max:255',
            'app_version' => 'sometimes|nullable|string|max:64',
            'os_version' => 'sometimes|nullable|string|max:64',
            'notification_preferences' => 'sometimes|nullable|array',
            'last_seen_at' => 'sometimes|boolean',
        ]);

        $touchSeen = (bool) ($validated['last_seen_at'] ?? false);
        unset($validated['last_seen_at']);

        if ($touchSeen) {
            $validated['last_seen_at'] = now();
        }

        $device->fill($validated)->save();

        return response()->json(['data' => $this->present($device->fresh() ?? $device)], 200);
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        $tokenId = $this->tokenId($request);
        if ($tokenId === null) {
            return response()->json(['error' => 'No active token'], 401);
        }

        $deleted = Device::query()
            ->where('id', $id)
            ->where('token_id', $tokenId)
            ->delete();

        if (! $deleted) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        return response()->json(['data' => ['deleted' => true]], 200);
    }

    protected function tokenId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token) {
            return null;
        }
        return (int) ($token->id ?? 0) ?: null;
    }

    protected function present(Device $device): array
    {
        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'device_name' => $device->device_name,
            'app_version' => $device->app_version,
            'os_version' => $device->os_version,
            'notification_preferences' => $device->notification_preferences,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
            'updated_at' => $device->updated_at?->toIso8601String(),
        ];
    }
}

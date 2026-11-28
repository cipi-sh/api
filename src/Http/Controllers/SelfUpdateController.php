<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiSelfUpdateService;
use CipiApi\Services\SelfUpdatePolicyRefusedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GET|PUT /self-update/policy — how the server updates Cipi: the signature
 * check and the delay (Cipi 5.18.0). Abilities updates-view, updates-manage.
 */
class SelfUpdateController extends Controller
{
    public function __construct(protected CipiSelfUpdateService $updates) {}

    public function show(): JsonResponse
    {
        return $this->respond(fn () => $this->updates->show());
    }

    public function update(Request $request): JsonResponse
    {
        $input = $request->validate(CipiSelfUpdateService::RULES);
        if ($input === []) {
            return response()->json(['error' => 'Nothing to change: verify, delay_hours'], 422);
        }

        return $this->respond(fn () => $this->updates->update($input));
    }

    /** @param  callable(): array  $action */
    protected function respond(callable $action): JsonResponse
    {
        try {
            return response()->json(['data' => $action()], 200);
        } catch (SelfUpdatePolicyRefusedException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}

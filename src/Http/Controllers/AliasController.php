<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AliasController extends Controller
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function list(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        $aliases = $this->validator->getAppAliases($name);
        return response()->json(['data' => $aliases], 200);
    }

    public function create(string $name, string $alias): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        if ($err = $this->validator->domainError($alias)) {
            return response()->json(['error' => $err], 422);
        }
        $usedBy = $this->validator->domainUsedBy($alias);
        if ($usedBy) {
            return response()->json(['error' => "Domain '{$alias}' is already used by app '{$usedBy}'"], 409);
        }

        $command = 'alias add ' . escapeshellarg($name) . ' ' . escapeshellarg($alias);
        $job = $this->jobs->dispatch('alias-create', $command, ['app' => $name, 'alias' => $alias]);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function delete(string $name, string $alias): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        $aliases = $this->validator->getAppAliases($name);
        if (! in_array($alias, $aliases, true)) {
            return response()->json(['error' => "Alias '{$alias}' not found for app '{$name}'"], 404);
        }

        $command = 'alias remove ' . escapeshellarg($name) . ' ' . escapeshellarg($alias);
        $job = $this->jobs->dispatch('alias-delete', $command, ['app' => $name, 'alias' => $alias]);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }
}

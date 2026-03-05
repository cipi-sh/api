<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AppController extends Controller
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function list(): JsonResponse
    {
        $apps = $this->validator->getApps();
        $data = [];
        foreach ($apps as $name => $app) {
            $data[] = [
                'app' => $name,
                'domain' => $app['domain'] ?? '',
                'php' => $app['php'] ?? '',
                'branch' => $app['branch'] ?? '',
                'repository' => $app['repository'] ?? '',
                'aliases' => $app['aliases'] ?? [],
                'created_at' => $app['created_at'] ?? '',
            ];
        }
        return response()->json(['data' => $data], 200);
    }

    public function show(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        $apps = $this->validator->getApps();
        $app = $apps[$name];
        $app['app'] = $name;
        return response()->json(['data' => $app], 200);
    }

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user' => 'required|string',
            'domain' => 'required|string',
            'repository' => 'required|string',
            'branch' => 'nullable|string|max:64',
            'php' => 'nullable|string',
        ]);

        if ($err = $this->validator->usernameError($validated['user'])) {
            return response()->json(['error' => $err], 422);
        }
        if ($err = $this->validator->domainError($validated['domain'])) {
            return response()->json(['error' => $err], 422);
        }
        if ($err = $this->validator->phpVersionError($validated['php'] ?? null)) {
            return response()->json(['error' => $err], 422);
        }
        if ($this->validator->appExists($validated['user'])) {
            return response()->json(['error' => "App '{$validated['user']}' already exists"], 409);
        }
        $usedBy = $this->validator->domainUsedBy($validated['domain']);
        if ($usedBy) {
            return response()->json(['error' => "Domain '{$validated['domain']}' is already used by app '{$usedBy}'"], 409);
        }
        if ($this->hasPendingAppCreate($validated['user'])) {
            return response()->json(['error' => "App '{$validated['user']}' is already being created"], 409);
        }

        $args = ['app create'];
        foreach ($validated as $k => $v) {
            if ($v !== null && $v !== '') {
                $args[] = '--' . $k . '=' . escapeshellarg((string) $v);
            }
        }

        $job = $this->jobs->dispatch('app-create', implode(' ', $args), $validated);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function edit(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'php' => 'nullable|string',
            'branch' => 'nullable|string|max:64',
            'repository' => 'nullable|string',
        ]);

        $filtered = array_filter($validated, fn ($v) => $v !== null && $v !== '');
        if (empty($filtered)) {
            return response()->json(['error' => 'Nothing to change. Provide php, branch, or repository.'], 422);
        }

        if (isset($filtered['php'])) {
            if ($err = $this->validator->phpVersionError($filtered['php'])) {
                return response()->json(['error' => $err], 422);
            }
        }

        $args = ['app edit', escapeshellarg($name)];
        foreach ($filtered as $k => $v) {
            $args[] = '--' . $k . '=' . escapeshellarg((string) $v);
        }

        $job = $this->jobs->dispatch('app-edit', implode(' ', $args), ['app' => $name] + $filtered);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function delete(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $command = 'app delete ' . escapeshellarg($name) . ' --force';
        $job = $this->jobs->dispatch('app-delete', $command, ['app' => $name]);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    protected function hasPendingAppCreate(string $user): bool
    {
        return CipiJob::where('type', 'app-create')
            ->whereIn('status', ['pending', 'running'])
            ->where('params->user', $user)
            ->exists();
    }
}

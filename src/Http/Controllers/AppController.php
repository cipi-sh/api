<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

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
                'engine' => $this->validator->getAppEngine($name),
                'octane' => $this->validator->getAppOctane($name),
                'octane_port' => $this->validator->getAppOctanePort($name),
                'node' => $this->validator->isNodeApp($name),
                'node_mode' => $this->validator->getNodeMode($name),
                'node_version' => $this->validator->getNodeVersion($name),
                'www_redirect' => $this->validator->getWwwRedirect($name),
                'redirect' => $this->validator->getAppRedirect($name),
                'redirects' => $this->validator->getAppRedirects($name),
                'proxies' => $this->validator->getAppProxies($name),
                'force_https' => $this->validator->isForceHttps($name),
                'suspended' => $this->validator->isSuspended($name),
                'basic_auth' => $this->validator->isBasicAuthEnabled($name),
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
        $app['suspended'] = $this->validator->isSuspended($name);
        $app['basic_auth'] = $this->validator->isBasicAuthEnabled($name);
        $app['engine'] = $this->validator->getAppEngine($name);
        $app['octane'] = $this->validator->getAppOctane($name);
        $app['octane_port'] = $this->validator->getAppOctanePort($name);
        $app['node'] = $this->validator->isNodeApp($name);
        $app['node_mode'] = $this->validator->getNodeMode($name);
        $app['node_version'] = $this->validator->getNodeVersion($name);
        $app['www_redirect'] = $this->validator->getWwwRedirect($name);
        $app['redirect'] = $this->validator->getAppRedirect($name);
        $app['redirects'] = $this->validator->getAppRedirects($name);
        $app['proxies'] = $this->validator->getAppProxies($name);
        $app['force_https'] = $this->validator->isForceHttps($name);
        return response()->json(['data' => $app], 200);
    }

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user' => 'required|string',
            'domain' => 'required|string',
            'repository' => [
                Rule::requiredIf(fn () => ! $request->boolean('custom')),
                'nullable',
                'string',
            ],
            'branch' => 'nullable|string|max:64',
            'php' => 'nullable|string',
            'custom' => 'nullable|boolean',
            'docroot' => 'nullable|string|max:128',
            'engine' => 'nullable|string',
            'octane' => 'nullable',
            // Node frontend apps (Cipi CLI ≥ 5.4.0)
            'node' => 'nullable|string|max:16',
            'framework' => 'nullable|string|max:32',
            'node_version' => 'nullable|string|max:8',
            'build' => 'nullable|string|max:256',
            'start' => 'nullable|string|max:256',
            'output' => 'nullable|string|max:128',
            'health_path' => 'nullable|string|max:128',
        ]);

        if (isset($validated['repository'])) {
            $validated['repository'] = trim((string) $validated['repository']);
            if ($validated['repository'] === '') {
                $validated['repository'] = null;
            }
        }
        if ($request->boolean('custom') && empty($validated['repository'])) {
            unset($validated['branch']);
        }

        if ($err = $this->validator->usernameError($validated['user'])) {
            return response()->json(['error' => $err], 422);
        }
        if ($err = $this->validator->domainError($validated['domain'])) {
            return response()->json(['error' => $err], 422);
        }
        if ($err = $this->validator->phpInstalledError($validated['php'] ?? null)) {
            return response()->json(['error' => $err], 422);
        }
        if ($err = $this->validator->engineError($validated['engine'] ?? null)) {
            return response()->json(['error' => $err], 422);
        }
        if ($err = $this->validator->octaneError($validated['octane'] ?? null)) {
            return response()->json(['error' => $err], 422);
        }
        if (! empty($validated['engine'])) {
            $validated['engine'] = $this->validator->normalizeEngine($validated['engine']);
        }
        $octane = $this->validator->normalizeOctane($validated['octane'] ?? null);
        unset($validated['octane']);
        if ($request->boolean('custom')) {
            unset($validated['engine']);
            if ($octane !== null) {
                return response()->json(['error' => 'Octane is only available for Laravel apps (not custom)'], 422);
            }
        }
        if (! empty($validated['docroot']) && ! preg_match('/^[a-zA-Z0-9_\-\/]+$/', $validated['docroot'])) {
            return response()->json(['error' => 'Invalid docroot format. Use alphanumeric characters, dashes, underscores, and slashes only.'], 422);
        }

        // Node frontend apps (Cipi CLI ≥ 5.4.0): --node=spa|static|ssr and/or --framework=…
        $isNode = $request->filled('node') || $request->filled('framework');
        if ($isNode) {
            if ($request->boolean('custom') || $octane !== null) {
                return response()->json(['error' => 'A Node app cannot be combined with custom or octane'], 422);
            }
            if (! empty($validated['engine'])) {
                return response()->json(['error' => 'Databases are not created with a Node app. Create one with the database API after creation.'], 422);
            }
            if (! empty($validated['php'])) {
                return response()->json(['error' => 'PHP version does not apply to Node apps'], 422);
            }
            if ($err = $this->validator->nodeModeError($validated['node'] ?? null)) {
                return response()->json(['error' => $err], 422);
            }
            if ($err = $this->validator->nodeFrameworkError($validated['framework'] ?? null)) {
                return response()->json(['error' => $err], 422);
            }
            if ($err = $this->validator->nodeVersionError($validated['node_version'] ?? null)) {
                return response()->json(['error' => $err], 422);
            }
            if ($err = $this->validator->nodeCommandError($validated['build'] ?? null, 'build command')) {
                return response()->json(['error' => $err], 422);
            }
            if ($err = $this->validator->nodeCommandError($validated['start'] ?? null, 'start command')) {
                return response()->json(['error' => $err], 422);
            }
            if ($err = $this->validator->nodeOutputError($validated['output'] ?? null)) {
                return response()->json(['error' => $err], 422);
            }
            if (! empty($validated['health_path']) && ($err = $this->validator->routePathError($validated['health_path']))) {
                return response()->json(['error' => $err], 422);
            }
        } else {
            foreach (['node_version', 'build', 'start', 'output', 'health_path'] as $nodeOnly) {
                if (! empty($validated[$nodeOnly])) {
                    return response()->json(['error' => "'{$nodeOnly}' requires a Node app (set 'node' or 'framework')"], 422);
                }
            }
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

        $isCustom = ! empty($validated['custom']);

        $args = ['app create'];
        if ($isCustom) {
            $args[] = '--custom';
        }
        if ($octane !== null) {
            $args[] = '--octane=' . escapeshellarg($octane);
            $validated['octane'] = $octane;
        }
        // API field → CLI flag (kebab-case where they differ).
        $flagNames = [
            'node_version' => 'node-version',
            'health_path' => 'health-path',
        ];
        foreach ($validated as $k => $v) {
            if ($k === 'custom' || $k === 'octane') {
                continue;
            }
            if ($v !== null && $v !== '') {
                $args[] = '--' . ($flagNames[$k] ?? $k) . '=' . escapeshellarg((string) $v);
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
            'domain' => 'nullable|string',
            // Node fields (Cipi CLI ≥ 5.4.0): Node apps, plus node_version to pin a Laravel app
            'node' => 'nullable|string|max:16',
            'node_version' => 'nullable|string|max:8',
            'build' => 'nullable|string|max:256',
            'start' => 'nullable|string|max:256',
            'output' => 'nullable|string|max:128',
            'health_path' => 'nullable|string|max:128',
        ]);

        $nodeFields = [];
        foreach (['node', 'node_version', 'build', 'start', 'output', 'health_path'] as $key) {
            $value = $validated[$key] ?? null;
            unset($validated[$key]);
            if (is_string($value) && trim($value) !== '') {
                $nodeFields[$key] = trim($value);
            }
        }

        $filtered = $this->validator->filterUnchangedAppEditFields($name, $validated);
        if (empty($filtered) && empty($nodeFields)) {
            return response()->json(['error' => 'Nothing to change. Provide a different php, branch, repository, domain, or a Node field.'], 422);
        }

        if (isset($filtered['php'])) {
            if ($err = $this->validator->phpInstalledError($filtered['php'])) {
                return response()->json(['error' => $err], 422);
            }
        }
        if (isset($filtered['domain'])) {
            if ($err = $this->validator->domainError($filtered['domain'])) {
                return response()->json(['error' => $err], 422);
            }
            $usedBy = $this->validator->domainUsedBy($filtered['domain'], $name);
            if ($usedBy) {
                return response()->json(['error' => "Domain '{$filtered['domain']}' is already used by app '{$usedBy}'"], 409);
            }
        }

        if (! empty($nodeFields)) {
            $isNodeApp = $this->validator->isNodeApp($name);
            foreach (['node', 'build', 'start', 'output', 'health_path'] as $nodeOnly) {
                if (isset($nodeFields[$nodeOnly]) && ! $isNodeApp) {
                    return response()->json(['error' => "'{$nodeOnly}' only applies to Node apps"], 422);
                }
            }
            if ($err = $this->validator->nodeModeError($nodeFields['node'] ?? null)) {
                return response()->json(['error' => $err], 422);
            }
            // node_version accepts 'default' on Laravel apps (follow the server default again).
            if ($err = $this->validator->nodeVersionError($nodeFields['node_version'] ?? null, ! $isNodeApp)) {
                return response()->json(['error' => $err], 422);
            }
            if ($err = $this->validator->nodeCommandError($nodeFields['build'] ?? null, 'build command')) {
                return response()->json(['error' => $err], 422);
            }
            if ($err = $this->validator->nodeCommandError($nodeFields['start'] ?? null, 'start command')) {
                return response()->json(['error' => $err], 422);
            }
            if ($err = $this->validator->nodeOutputError($nodeFields['output'] ?? null)) {
                return response()->json(['error' => $err], 422);
            }
            if (isset($nodeFields['health_path']) && ($err = $this->validator->routePathError($nodeFields['health_path']))) {
                return response()->json(['error' => $err], 422);
            }
        }

        $flagNames = [
            'node_version' => 'node-version',
            'health_path' => 'health-path',
        ];
        $args = ['app edit', escapeshellarg($name)];
        foreach ($filtered + $nodeFields as $k => $v) {
            $args[] = '--' . ($flagNames[$k] ?? $k) . '=' . escapeshellarg((string) $v);
        }

        $job = $this->jobs->dispatch('app-edit', implode(' ', $args), ['app' => $name] + $filtered + $nodeFields);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * Recreate the GitHub/GitLab webhook (optionally rotate CIPI_WEBHOOK_TOKEN).
     * Requires Cipi CLI ≥ 5.0.6.
     */
    public function webhookRecreate(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'rotate_secret' => 'sometimes|boolean',
        ]);

        $rotate = (bool) ($validated['rotate_secret'] ?? false);
        $command = 'app webhook recreate ' . escapeshellarg($name);
        if ($rotate) {
            $command .= ' --rotate-secret';
        }

        $job = $this->jobs->dispatch('app-webhook-recreate', $command, [
            'app' => $name,
            'rotate_secret' => $rotate,
        ]);

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

    public function suspend(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        if ($this->validator->isSuspended($name)) {
            return response()->json(['error' => "App '{$name}' is already suspended"], 409);
        }

        $command = 'app suspend ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('app-suspend', $command, ['app' => $name]);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function unsuspend(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        if (! $this->validator->isSuspended($name)) {
            return response()->json(['error' => "App '{$name}' is not suspended"], 409);
        }

        $command = 'app unsuspend ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('app-unsuspend', $command, ['app' => $name]);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * Restore the app home to the permission model Cipi created it with
     * (`cipi app fix-permissions`, Cipi CLI ≥ 5.2.1). Async job.
     */
    public function fixPermissions(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $command = 'app fix-permissions ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('app-fix-permissions', $command, ['app' => $name]);

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

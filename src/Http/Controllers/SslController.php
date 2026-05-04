<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\SslInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SslController extends Controller
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
        protected SslInspectorService $inspector,
    ) {}

    public function install(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $command = 'ssl install ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('ssl-install', $command, ['app' => $name]);
        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function info(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $domain = $this->validator->getAppDomain($name);
        if (! $domain) {
            return response()->json(['error' => "App '{$name}' has no domain"], 404);
        }

        $primary = $this->inspector->inspect($domain);
        $aliases = [];
        foreach ($this->validator->getAppAliases($name) as $alias) {
            $aliases[] = $this->inspector->inspect((string) $alias);
        }

        return response()->json([
            'data' => [
                'app' => $name,
                'domain' => $domain,
                'certificate' => $primary,
                'aliases' => array_values(array_filter($aliases)),
            ],
        ], 200);
    }
}

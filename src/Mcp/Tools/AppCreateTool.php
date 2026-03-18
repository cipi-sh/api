<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new app. Validates domain/username/PHP synchronously and dispatches async job. Returns job_id for polling.')]
class AppCreateTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('apps-create')) {
            return Response::text('Permission denied: apps-create required');
        }

        $user = $request->get('user');
        $domain = $request->get('domain');
        $repository = $request->get('repository');
        $branch = $request->get('branch', 'main');
        $php = $request->get('php', '8.4');
        $custom = (bool) $request->get('custom', false);
        $docroot = $request->get('docroot');

        if ($err = $this->validator->usernameError($user ?? '')) {
            return Response::text("Error: {$err}");
        }
        if ($err = $this->validator->domainError($domain ?? '')) {
            return Response::text("Error: {$err}");
        }
        if ($err = $this->validator->phpVersionError($php)) {
            return Response::text("Error: {$err}");
        }
        if ($docroot && ! preg_match('/^[a-zA-Z0-9_\-\/]+$/', $docroot)) {
            return Response::text('Error: Invalid docroot format. Use alphanumeric characters, dashes, underscores, and slashes only.');
        }
        if ($this->validator->appExists($user)) {
            return Response::text("Error: App '{$user}' already exists");
        }
        $usedBy = $this->validator->domainUsedBy($domain);
        if ($usedBy) {
            return Response::text("Error: Domain '{$domain}' is already used by app '{$usedBy}'");
        }

        $params = compact('user', 'domain', 'repository', 'branch', 'php', 'custom', 'docroot');
        $args = ['app create'];
        if ($custom) {
            $args[] = '--custom';
        }
        foreach ($params as $k => $v) {
            if ($k === 'custom') {
                continue;
            }
            if ($v !== null && $v !== '') {
                $args[] = '--' . $k . '=' . escapeshellarg((string) $v);
            }
        }

        $job = $this->jobs->dispatch('app-create', implode(' ', $args), $params);
        return Response::text("Job dispatched: {$job->id} (status: pending). Poll GET /api/jobs/{$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user' => $schema->string()->description('App username (slug, 3-32 lowercase alphanumeric)')->required(),
            'domain' => $schema->string()->description('Primary domain')->required(),
            'repository' => $schema->string()->description('Git repository URL (SSH)')->required(),
            'branch' => $schema->string()->description('Branch (default: main)'),
            'php' => $schema->string()->description('PHP version (default: 8.4)'),
            'custom' => $schema->boolean()->description('Create a custom (non-Laravel) app with classic deploy (no zero-downtime)'),
            'docroot' => $schema->string()->description('Document root path for custom apps (e.g. dist, www, public). Default: /'),
        ];
    }
}

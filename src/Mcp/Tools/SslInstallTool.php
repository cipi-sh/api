<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiSslDnsCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Install (or reissue) the Let\'s Encrypt certificate for an app. Optional DNS-01 via Cloudflare (dns=cloudflare, account from SslDnsList) with wildcard=true for *.<apex>; http=true goes back to HTTP-01. Without options a DNS-01 certificate is reissued over DNS-01 (Cipi ≥ 5.5.1). Dispatches an async job; poll JobShow.')]
class SslInstallTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
        protected CipiSslDnsCliService $dns,
    ) {}

    public function handle(Request $request): Response
    {
        [$name, $error] = McpArgValidator::requiredString($request, 'name');
        if ($error !== null) {
            return $error;
        }

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }

        $dns = is_string($request->get('dns')) && $request->get('dns') !== '' ? $request->get('dns') : null;
        $account = is_string($request->get('account')) && $request->get('account') !== '' ? $request->get('account') : null;
        $wildcard = is_bool($request->get('wildcard')) ? $request->get('wildcard') : null;
        $http = $request->get('http') === true;
        if ($err = $this->dns->installOptionsError($dns, $account, $wildcard, $http)) {
            return Response::text("Error: {$err}");
        }

        $command = $this->dns->installCommand($name, $dns, $account, $wildcard, $http);
        $params = array_filter(
            ['app' => $name, 'dns' => $dns, 'account' => $account, 'wildcard' => $wildcard, 'http' => $http ?: null],
            fn ($v) => $v !== null,
        );
        $job = $this->jobs->dispatch('ssl-install', $command, $params);
        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'dns' => $schema->string()->enum(['cloudflare'])->description('DNS-01 provider (Cipi ≥ 5.5.0)'),
            'account' => $schema->string()->description('Cloudflare account name from SslDnsList (default: the account the certificate was issued with, else "default")'),
            'wildcard' => $schema->boolean()->description('true adds *.<apex> (needs DNS-01), false drops it; omit to keep the previous choice'),
            'http' => $schema->boolean()->description('Force HTTP-01 (e.g. move a DNS-01 certificate back to HTTP-01)'),
        ];
    }
}

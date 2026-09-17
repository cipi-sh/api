<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiRoutesCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add (or update) a prefix reverse proxy on an app (cipi proxy add): location ^~ <prefix> with proxy_pass, WebSocket upgrade and X-Forwarded-* headers. Proxies to loopback ports Cipi already uses (databases, Meilisearch, other apps) are refused. Requires Cipi CLI ≥ 5.4.1.')]
class ProxyAddTool extends Tool
{
    public function __construct(
        protected CipiRoutesCliService $routes,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        [$name, $error] = McpArgValidator::requiredString($request, 'name');
        if ($error !== null) {
            return $error;
        }
        [$prefix, $error] = McpArgValidator::requiredString($request, 'prefix');
        if ($error !== null) {
            return $error;
        }
        [$upstream, $error] = McpArgValidator::requiredString($request, 'upstream');
        if ($error !== null) {
            return $error;
        }

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if ($err = $this->validator->routePathError($prefix)) {
            return Response::text("Error: {$err}");
        }
        if ($err = $this->validator->proxyUpstreamError($upstream)) {
            return Response::text("Error: {$err}");
        }

        $timeout = (int) ($request->get('timeout') ?? 60);
        if ($timeout < 1 || $timeout > 3600) {
            return Response::text('Error: timeout must be 1-3600 seconds');
        }

        try {
            $data = $this->routes->addProxy(
                $name,
                $prefix,
                $upstream,
                (bool) ($request->get('strip_prefix') ?? false),
                (bool) ($request->get('preserve_host') ?? false),
                $timeout,
                $request->get('buffering') === null ? true : (bool) $request->get('buffering'),
            );

            return Response::text(json_encode($data, JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'prefix' => $schema->string()->description('Path prefix to proxy (e.g. /api/)')->required(),
            'upstream' => $schema->string()->description('Upstream URL: http(s)://host[:port][/path] (a path requires strip_prefix)')->required(),
            'strip_prefix' => $schema->boolean()->description('Strip the prefix before passing upstream (/api/users → <upstream>/users; sends X-Forwarded-Prefix)'),
            'preserve_host' => $schema->boolean()->description('Send the original Host header instead of the upstream host'),
            'timeout' => $schema->integer()->description('Proxy read timeout in seconds (default 60, max 3600)'),
            'buffering' => $schema->boolean()->description('Response buffering (default true; false for SSE / long polling / streamed downloads)'),
        ];
    }
}

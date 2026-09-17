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

#[Description('Add (or update) a path redirect on an app (cipi redirect add). A `from` ending in / is a prefix match: the rest of the request URI is appended to the target. `to` is a same-app /path or an http(s):// URL. Requires Cipi CLI ≥ 5.4.1.')]
class RedirectAddTool extends Tool
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
        [$from, $error] = McpArgValidator::requiredString($request, 'from');
        if ($error !== null) {
            return $error;
        }
        [$to, $error] = McpArgValidator::requiredString($request, 'to');
        if ($error !== null) {
            return $error;
        }

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if ($err = $this->validator->routePathError($from)) {
            return Response::text("Error: {$err}");
        }
        if (! str_starts_with($to, '/') && ($err = $this->validator->redirectUrlError($to))) {
            return Response::text("Error: {$err}");
        }

        $code = (int) ($request->get('code') ?? 301);
        if (! in_array($code, [301, 302, 307, 308], true)) {
            return Response::text('Error: code must be 301, 302, 307 or 308');
        }
        $keepPath = $request->get('keep_path');
        $keepPath = $keepPath === null ? true : (bool) $keepPath;

        try {
            return Response::text(json_encode($this->routes->addRedirect($name, $from, $to, $code, $keepPath), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'from' => $schema->string()->description('Source path (decoded). Ending in / = prefix match (e.g. /blog/)')->required(),
            'to' => $schema->string()->description('Target: a same-app /path or an http(s):// URL')->required(),
            'code' => $schema->integer()->description('Redirect code: 301 (default), 302, 307 or 308'),
            'keep_path' => $schema->boolean()->description('For prefix redirects: append the rest of the URI to the target (default true)'),
        ];
    }
}

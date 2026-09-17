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

#[Description('Set the whole-app redirect: every hostname of the app redirects to a target URL in one hop (cipi redirect set). Path and query are kept by default. Refuses a target served by the app itself. Requires Cipi CLI ≥ 5.4.1.')]
class RedirectSetTool extends Tool
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
        [$to, $error] = McpArgValidator::requiredString($request, 'to');
        if ($error !== null) {
            return $error;
        }

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if ($err = $this->validator->redirectUrlError($to)) {
            return Response::text("Error: {$err}");
        }

        $code = (int) ($request->get('code') ?? 301);
        if (! in_array($code, [301, 302, 307, 308], true)) {
            return Response::text('Error: code must be 301, 302, 307 or 308');
        }
        $keepPath = $request->get('keep_path');
        $keepPath = $keepPath === null ? true : (bool) $keepPath;

        try {
            $data = $this->routes->setAppRedirect($name, $to, $code, $keepPath);

            return Response::text(json_encode($data, JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'to' => $schema->string()->description('Target URL (http(s)://host[/path])')->required(),
            'code' => $schema->integer()->description('Redirect code: 301 (default), 302, 307 or 308'),
            'keep_path' => $schema->boolean()->description('Keep request path and query on the target (default true)'),
        ];
    }
}

<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiNodeCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('List installed Node runtimes with the server default and the apps on each major (cipi node list). Installing/removing runtimes stays on the host CLI. Requires Cipi CLI ≥ 5.4.1.')]
#[IsReadOnly]
class NodeRuntimesTool extends Tool
{
    public function __construct(
        protected CipiNodeCliService $node,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->node->runtimes(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

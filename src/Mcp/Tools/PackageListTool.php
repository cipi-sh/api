<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiPackageCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Optional host packages catalog with install state (cipi package list): image-optimizers, ffmpeg, imagemagick, poppler-utils, … Installing/removing runs as root and stays on the host CLI. Requires Cipi 5.2.2+.')]
#[IsReadOnly]
class PackageListTool extends Tool
{
    public function __construct(
        protected CipiPackageCliService $packages,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->packages->list(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

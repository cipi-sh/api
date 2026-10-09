<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiDiskCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Size of every database per installed engine (cipi disk db --json), one entry per engine: MariaDB and PostgreSQL databases in MB (size_mb), Valkey logical databases with keys plus memory_mb, Meilisearch indexes with documents; each engine also reports on_disk_mb and a note when it did not answer. Engines that are not installed are left out. Read-only. Requires Cipi 5.5.2+ (API sudoers).')]
#[IsReadOnly]
class DiskDatabasesTool extends Tool
{
    public function __construct(
        protected CipiDiskCliService $disk,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->disk->databases(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiDiskCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Disk usage of the server and every app (cipi disk --json): the filesystem /home is on (mount, size_gb, used_gb, free_gb, used_percent), then each app largest first with files_gb (home: releases, shared storage, logs), database_gb, total_gb, percent of the disk, the soft limit (limit_gb, limit_percent, over_limit) and the all-apps / everything-else totals. Sizes are measured on request — a server with large apps takes a few seconds. Read-only; set a limit with AppLimitsUpdate. Requires Cipi 5.5.2+ (API sudoers).')]
#[IsReadOnly]
class DiskUsageTool extends Tool
{
    public function __construct(
        protected CipiDiskCliService $disk,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->disk->usage(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

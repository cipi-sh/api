<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiServerMonitorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('List system service status (same as `cipi service list`). Optionally filter by service name (e.g. nginx, mariadb, php8.5-fpm).')]
#[IsReadOnly]
class ServiceListTool extends Tool
{
    public function __construct(
        protected CipiServerMonitorService $monitor,
    ) {}

    public function handle(Request $request): Response
    {
        $service = $request->get('service');

        try {
            return Response::text($this->monitor->serviceList($service));
        } catch (\InvalidArgumentException $e) {
            return Response::text('Error: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->description('Optional service name (nginx, mariadb, redis-server, supervisor, fail2ban, php, php8.5-fpm, …)'),
        ];
    }
}

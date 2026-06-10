<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiAppLogsService;
use CipiApi\Services\CipiLogReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Read recent app logs (snapshot, non-blocking). Same types as `cipi app logs`: all, nginx, php, worker, deploy, laravel.')]
#[IsReadOnly]
class AppLogsTool extends Tool
{
    public function __construct(
        protected CipiAppLogsService $appLogs,
    ) {}

    public function handle(Request $request): Response
    {
        $name = $request->get('name');
        $type = $request->get('type', 'all');
        $lines = (int) ($request->get('lines', CipiLogReader::DEFAULT_LINES));

        try {
            return Response::text($this->appLogs->read($name, $type, $lines));
        } catch (\InvalidArgumentException $e) {
            return Response::text('Error: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'type' => $schema->string()
                ->description('Log type: all, nginx, php, worker, deploy, or laravel')
                ->enum(CipiAppLogsService::TYPES)
                ->default('all'),
            'lines' => $schema->integer()
                ->description('Number of lines per log file (max ' . CipiLogReader::MAX_LINES . ')')
                ->default(CipiLogReader::DEFAULT_LINES),
        ];
    }
}

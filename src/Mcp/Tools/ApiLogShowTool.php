<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiApiLogService;
use CipiApi\Services\CipiLogReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Read recent Laravel logs for the Cipi API host application (storage/logs/laravel.log or daily files).')]
#[IsReadOnly]
class ApiLogShowTool extends Tool
{
    public function __construct(
        protected CipiApiLogService $apiLogs,
    ) {}

    public function handle(Request $request): Response
    {
        $lines = (int) ($request->get('lines', CipiLogReader::DEFAULT_LINES));
        $date = $request->get('date');

        return Response::text($this->apiLogs->read($date, $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'lines' => $schema->integer()
                ->description('Number of lines per log file (max ' . CipiLogReader::MAX_LINES . ')')
                ->default(CipiLogReader::DEFAULT_LINES),
            'date' => $schema->string()
                ->description('Optional date (YYYY-MM-DD) for a specific daily log file'),
        ];
    }
}

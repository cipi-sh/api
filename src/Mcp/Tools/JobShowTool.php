<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobStatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Poll a background job by id. Returns status (pending, running, completed, failed). When finished, includes parsed result and CLI output.')]
#[IsReadOnly]
class JobShowTool extends Tool
{
    public function __construct(
        protected CipiJobStatusService $jobStatus,
    ) {}

    public function handle(Request $request): Response
    {
        $id = $request->get('id');
        $includeOutput = (bool) $request->get('include_output', true);

        $data = $this->jobStatus->find($id);
        if ($data === null) {
            return Response::text("Error: Job '{$id}' not found");
        }

        if (! $includeOutput) {
            unset($data['output']);
        }

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Job UUID returned by async MCP tools')->required(),
            'include_output' => $schema->boolean()
                ->description('Include raw CLI output when the job has finished (default: true)')
                ->default(true),
        ];
    }
}

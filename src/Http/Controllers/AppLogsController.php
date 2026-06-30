<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Mcp\Support\McpProductionContent;
use CipiApi\Services\CipiAppLogsService;
use CipiApi\Services\CipiLogReader;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AppLogsController extends Controller
{
    public function __construct(
        protected CipiAppLogsService $appLogs,
        protected CipiValidationService $validator,
        protected CipiLogReader $logReader,
    ) {}

    public function show(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'type' => 'nullable|string|in:' . implode(',', CipiAppLogsService::TYPES),
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:' . CipiLogReader::MAX_PER_PAGE,
        ]);

        $type = strtolower($validated['type'] ?? 'all');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? CipiLogReader::DEFAULT_PER_PAGE);

        try {
            $payload = $this->appLogs->readPaginated($name, $type, $page, $perPage);
            $payload = $this->redactFiles($payload);

            return response()->json(['data' => $payload], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * @param  array{
     *     app: string,
     *     type: string,
     *     page: int,
     *     per_page: int,
     *     available_types: list<string>,
     *     files: list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     * }  $payload
     * @return array{
     *     app: string,
     *     type: string,
     *     page: int,
     *     per_page: int,
     *     available_types: list<string>,
     *     files: list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>,
     *     warnings?: list<string>
     * }
     */
    protected function redactFiles(array $payload): array
    {
        $warnings = [];
        $redactedFiles = [];

        foreach ($payload['files'] as $file) {
            $joined = implode("\n", $file['lines']);
            $redacted = McpProductionContent::redact($joined);

            if (McpProductionContent::hasHighRiskPatterns($redacted)) {
                $warnings[] = McpProductionContent::HIGH_RISK_ALERT;
            }

            $file['lines'] = $redacted === '' ? [] : preg_split("/\r\n|\r|\n/", $redacted) ?: [];
            $redactedFiles[] = $file;
        }

        $payload['files'] = $redactedFiles;

        if ($warnings !== []) {
            $payload['warnings'] = array_values(array_unique($warnings));
        }

        return $payload;
    }
}

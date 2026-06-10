<?php

namespace CipiApi\Services;

use CipiApi\Models\CipiJob;

class CipiJobStatusService
{
    public const MAX_OUTPUT_CHARS = 50000;

    public function __construct(
        protected CipiOutputParser $parser,
    ) {}

    public function find(string $id): ?array
    {
        $job = CipiJob::find($id);

        return $job ? $this->format($job, true) : null;
    }

    public function format(CipiJob $job, bool $includeOutput = true): array
    {
        $data = [
            'id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];

        if (! in_array($job->status, ['completed', 'failed'], true)) {
            return $data;
        }

        $data['exit_code'] = $job->exit_code;
        $data['result'] = $this->parser->parse(
            $job->type,
            $job->output ?? '',
            $job->status === 'completed',
        );

        if ($includeOutput) {
            $data['output'] = $this->truncateOutput($job->output ?? '');
        }

        return $data;
    }

    protected function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_CHARS) {
            return $output;
        }

        return substr($output, -self::MAX_OUTPUT_CHARS)
            . "\n\n[... output truncated to last " . self::MAX_OUTPUT_CHARS . ' characters ...]';
    }
}

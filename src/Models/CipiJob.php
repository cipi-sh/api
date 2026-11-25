<?php

namespace CipiApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CipiJob extends Model
{
    use HasUuids;

    protected $table = 'cipi_jobs';

    protected $fillable = [
        'type',
        'params',
        'status',
        'output',
        'exit_code',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
        ];
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running']);
    }

    /** How much of a running job's output is kept: its last characters. */
    public const PROGRESS_CHARS = 50000;

    /**
     * What a running job has printed so far. Only while it runs, and never
     * what makes it fail: the output that counts is the one markCompleted() saves.
     */
    public function recordProgress(string $output): void
    {
        if ($this->status !== 'running') {
            return;
        }
        if (strlen($output) > self::PROGRESS_CHARS) {
            $output = substr($output, -self::PROGRESS_CHARS);
            $cut = strpos($output, "\n");
            $output = $cut === false ? $output : substr($output, $cut + 1); // from the start of a line
        }
        try {
            $this->update(['output' => $output]);
        } catch (\Throwable) {
            // A busy database: the next second tries again.
        }
    }

    public function markCompleted(string $output, int $exitCode): void
    {
        $this->update([
            'status' => $exitCode === 0 ? 'completed' : 'failed',
            'output' => $output,
            'exit_code' => $exitCode,
        ]);
    }
}

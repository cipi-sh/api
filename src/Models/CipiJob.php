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

    public function markCompleted(string $output, int $exitCode): void
    {
        $this->update([
            'status' => $exitCode === 0 ? 'completed' : 'failed',
            'output' => $output,
            'exit_code' => $exitCode,
        ]);
    }
}

<?php

namespace CipiApi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CipiJob extends Model
{
    use HasUuids;

    protected $table = 'cipi_jobs';

    protected $fillable = [
        'type',
        'app',
        'params',
        'status',
        'output',
        'log_path',
        'exit_code',
        'started_at',
        'finished_at',
        'duration_seconds',
        'triggered_by',
        'token_id',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => 'running',
            'started_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function markCompleted(string $output, int $exitCode): void
    {
        $startedAt = $this->started_at ?? $this->freshTimestamp();
        $finishedAt = $this->freshTimestamp();
        $duration = max(0, $finishedAt->getTimestamp() - $startedAt->getTimestamp());

        $this->forceFill([
            'status' => $exitCode === 0 ? 'completed' : 'failed',
            'output' => $output,
            'exit_code' => $exitCode,
            'finished_at' => $finishedAt,
            'duration_seconds' => $duration,
        ])->save();
    }

    public function scopeForApp(Builder $query, string $app): Builder
    {
        return $query->where(function (Builder $q) use ($app) {
            $q->where('app', $app)
                ->orWhere('params->app', $app)
                ->orWhere('params->user', $app);
        });
    }

    public function scopeOfTypes(Builder $query, array $types): Builder
    {
        return $query->whereIn('type', $types);
    }

    public function isDeploy(): bool
    {
        return in_array($this->type, ['app-deploy', 'app-deploy-rollback', 'app-deploy-unlock'], true);
    }

    public function appName(): ?string
    {
        if (! empty($this->app)) {
            return $this->app;
        }
        $params = (array) ($this->params ?? []);
        return $params['app'] ?? $params['user'] ?? null;
    }
}

<?php

namespace CipiApi\Listeners;

use CipiApi\Events\JobStateChanged;
use CipiApi\Models\CipiJob;
use CipiApi\Notifications\PushNotificationService;

class SendJobNotifications
{
    public function __construct(protected PushNotificationService $push) {}

    public function handle(JobStateChanged $event): void
    {
        if ($event->state === 'started') {
            return;
        }

        $job = $event->job;
        $type = $this->mapType($job, $event->state);
        if ($type === null) {
            return;
        }

        $payload = $this->payload($job, $type);
        $this->push->fanout($type, $payload, $job->token_id ? (int) $job->token_id : null);
    }

    protected function mapType(CipiJob $job, string $state): ?string
    {
        $success = $state === 'completed';
        return match ($job->type) {
            'app-deploy' => $success ? 'deploy.success' : 'deploy.failed',
            'app-deploy-rollback' => $success ? 'deploy.rolled_back' : 'deploy.failed',
            'app-deploy-unlock' => $success ? 'deploy.unlocked' : null,
            'ssl-install' => $success ? 'ssl.installed' : 'ssl.failed',
            'db-backup' => $success ? 'db.backup_completed' : 'db.backup_failed',
            'app-create' => $success ? 'app.created' : null,
            'app-delete' => $success ? 'app.deleted' : null,
            default => null,
        };
    }

    protected function payload(CipiJob $job, string $type): array
    {
        $app = $job->appName();
        $params = (array) ($job->params ?? []);
        $duration = $job->duration_seconds;

        $title = match ($type) {
            'deploy.success' => 'Deploy completato',
            'deploy.failed' => 'Deploy fallito',
            'deploy.rolled_back' => 'Rollback completato',
            'deploy.unlocked' => 'Deploy sbloccato',
            'ssl.installed' => 'SSL installato',
            'ssl.failed' => 'SSL fallito',
            'db.backup_completed' => 'Backup DB completato',
            'db.backup_failed' => 'Backup DB fallito',
            'app.created' => 'App creata',
            'app.deleted' => 'App eliminata',
            default => 'Cipi',
        };

        $bodyParts = [];
        if ($app) {
            $bodyParts[] = $app;
        }
        if ($duration !== null) {
            $bodyParts[] = $duration . 's';
        }
        $body = $bodyParts ? implode(' · ', $bodyParts) : ($job->type);

        return [
            'title' => $title,
            'body' => $body,
            'data' => array_filter([
                'job_id' => $job->id,
                'job_type' => $job->type,
                'app' => $app,
                'duration_seconds' => $duration,
                'exit_code' => $job->exit_code,
                'params' => $params ?: null,
            ], fn ($v) => $v !== null),
        ];
    }
}

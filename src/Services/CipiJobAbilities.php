<?php

namespace CipiApi\Services;

use CipiApi\Models\CipiJob;

/**
 * The token ability each job type is created under — the one the route (or
 * MCP tool) that queues it enforces. Reading a job back (GET /api/jobs/{id})
 * needs the same ability: a job's output is the CLI's, and for app-create,
 * db-create and db-password it holds a password. A token without the ability
 * gets the same 404 as a job that does not exist, so an id alone tells nothing.
 *
 * Every type {@see CipiJobService::dispatch()} accepts must be listed here:
 * dispatch refuses an unknown type, so a job type added without an ability can
 * neither be queued nor read by everybody by mistake.
 */
final class CipiJobAbilities
{
    /** @var array<string, string> job type => ability */
    public const ABILITIES = [
        'app-create' => 'apps-create',
        'app-edit' => 'apps-edit',
        'app-webhook-recreate' => 'apps-edit',
        'app-fix-permissions' => 'apps-edit',
        'app-limits' => 'apps-edit',
        'app-delete' => 'apps-delete',
        'app-suspend' => 'apps-suspend',
        'app-unsuspend' => 'apps-suspend',
        'app-artisan' => 'apps-artisan',
        'app-run' => 'apps-run',
        'app-deploy' => 'deploy-manage',
        'app-deploy-rollback' => 'deploy-manage',
        'app-deploy-unlock' => 'deploy-manage',
        'app-yml-apply' => 'yml-manage',
        'alias-create' => 'aliases-create',
        'alias-delete' => 'aliases-delete',
        'www-add' => 'www-manage',
        'www-force-to-root' => 'www-manage',
        'www-force-from-root' => 'www-manage',
        'www-clear' => 'www-manage',
        'ssl-install' => 'ssl-manage',
        'ssl-force' => 'ssl-manage',
        'db-create' => 'dbs-create',
        'db-backup' => 'dbs-manage',
        'db-restore' => 'dbs-manage',
        'db-password' => 'dbs-manage',
        'db-install' => 'dbs-manage',
        'php-install' => 'php-manage',
        'service-restart' => 'services-manage',
        'node-restart' => 'node-manage',
    ];

    public static function forType(string $type): ?string
    {
        return self::ABILITIES[$type] ?? null;
    }

    /**
     * Whether the authenticated user's current token may read the job.
     *
     * @param  mixed  $user  The Sanctum user of the request ({@see \Laravel\Sanctum\HasApiTokens::tokenCan()}).
     */
    public static function canRead(mixed $user, CipiJob $job): bool
    {
        $ability = self::forType((string) $job->type);
        if ($ability === null || $user === null || ! method_exists($user, 'tokenCan')) {
            return false;
        }

        return (bool) $user->tokenCan($ability);
    }
}

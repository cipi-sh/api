<?php

namespace CipiApi\Services;

/**
 * How long the queue worker lets a job run before it stops it.
 *
 * Ten minutes for every command but a deploy. A deploy is long by nature:
 * after the clone and Composer comes the frontend build, and Cipi's recipes
 * give that step alone up to 30 minutes (Cipi 5.18.0; before, Deployer stopped
 * it after 300 seconds). With ten minutes for the whole job, a deploy started
 * from the panel was stopped half way through a build that the same deploy,
 * started from the server's shell or by a Git push, would have finished.
 */
class CipiJobTimeout
{
    /** Seconds for a job. */
    public const SECONDS = 600;

    /** Seconds for a deploy. */
    public const DEPLOY_SECONDS = 3600;

    /** Exit code of a job the worker stopped: the one timeout(1) uses. */
    public const EXIT_CODE = 124;

    /**
     * @param  string  $command  what follows `cipi`, as CipiJobService::dispatch() gets it
     */
    public static function forCommand(string $command): int
    {
        return self::isDeploy($command) ? self::DEPLOY_SECONDS : self::SECONDS;
    }

    /** `deploy <app>` and nothing after it: a rollback, an unlock or a status is quick. */
    public static function isDeploy(string $command): bool
    {
        return preg_match('/^deploy \S+$/', trim($command)) === 1;
    }

    /**
     * What a job stopped by the worker says, under whatever it had printed.
     * The command may be past saving or still at work: the server knows.
     */
    public static function stoppedOutput(?string $soFar, string $command, int $seconds): string
    {
        $soFar = rtrim((string) $soFar);
        $note = sprintf(
            '[cipi-api] Stopped after %d minutes: the time the API gives a job of this kind. The command had not ended.',
            intdiv($seconds, 60)
        );
        if (self::isDeploy($command)) {
            $app = trim(substr(trim($command), strlen('deploy ')), "'\"");
            $note .= sprintf(' On the server, `cipi deploy %s --status` says whether a deploy is still running, and the deploy log how far it went.', $app);
        }

        return $soFar === '' ? $note : $soFar . "\n\n" . $note;
    }
}

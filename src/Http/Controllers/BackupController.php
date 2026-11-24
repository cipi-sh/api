<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiBackupCliService;
use CipiApi\Services\CipiJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Backup profiles (Cipi CLI ≥ 5.9.0): status, runs, run now, verify, and the
 * profiles themselves. Every route answers 503 on an older core.
 *
 * Destinations are read-only here: adding a bucket takes an S3 secret key,
 * which this API could only pass on a command line. `cipi backup configure`
 * and `cipi backup destination add` are run on the server.
 */
class BackupController extends Controller
{
    protected const PROFILE_FIELDS = [
        'scope', 'apps', 'databases', 'exclude_databases', 'exclude_tables', 'exclude_paths',
        'every', 'cron', 'destinations', 'keep', 'keep_days', 'keep_weeks', 'encrypt',
    ];

    public function __construct(
        protected CipiBackupCliService $backup,
        protected CipiJobService $jobs,
    ) {}

    /**
     * Destinations (never their keys), profiles with what each covers right
     * now, last run, overdue and running flags, and what no enabled profile
     * takes. Synchronous; changes nothing on the server.
     */
    public function status(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->backup->status()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * The runs each destination holds, newest first, per profile. Lists every
     * bucket, so it can take a few seconds. Synchronous.
     */
    public function runs(Request $request): JsonResponse
    {
        $profile = $request->query('profile');
        $profile = is_string($profile) && $profile !== '' ? $profile : null;
        if ($profile !== null && ! $this->backup->isValidName($profile)) {
            return response()->json(['error' => "Invalid profile name '{$profile}'"], 422);
        }

        try {
            return response()->json(['data' => $this->backup->runs($profile)], 200);
        } catch (\RuntimeException $e) {
            return $this->cliError($e);
        }
    }

    /**
     * Start a backup run in the background — one profile, or every enabled
     * one when `profile` is omitted. Returns as soon as it has started; the
     * result shows in the status (`running`, then `last`).
     */
    public function run(Request $request): JsonResponse
    {
        $profile = $request->input('profile');
        $profile = is_string($profile) && $profile !== '' ? $profile : null;
        if ($profile !== null && ! $this->backup->isValidName($profile)) {
            return response()->json(['error' => "Invalid profile name '{$profile}'"], 422);
        }

        try {
            $this->backup->run($profile);

            return response()->json(['data' => ['started' => true, 'profile' => $profile]], 202);
        } catch (\RuntimeException $e) {
            return $this->cliError($e);
        }
    }

    /**
     * Read the newest run of a profile (or of each) back and check that every
     * archive in it opens. Async job: it downloads from the bucket when there
     * is no local copy.
     */
    public function verify(Request $request): JsonResponse
    {
        $profile = $request->input('profile');
        $profile = is_string($profile) && $profile !== '' ? $profile : null;
        if ($profile !== null && ! $this->backup->isValidName($profile)) {
            return response()->json(['error' => "Invalid profile name '{$profile}'"], 422);
        }

        try {
            $this->backup->requireCore();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        $command = 'backup verify';
        $params = [];
        if ($profile !== null) {
            $command .= ' --profile=' . escapeshellarg($profile);
            $params['profile'] = $profile;
        }
        $job = $this->jobs->dispatch('backup-verify', $command, $params);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /** Create a profile. `name` and `destinations` are required. */
    public function createProfile(Request $request): JsonResponse
    {
        $name = $request->input('name');
        if (! is_string($name) || ! $this->backup->isValidName($name)) {
            return response()->json(['error' => 'Invalid profile name (lowercase letters, digits and dashes, 2-32 characters)'], 422);
        }
        if (! $request->has('destinations')) {
            return response()->json(['error' => 'destinations is required: local, s3 or a named destination', 'errors' => ['destinations' => 'required']], 422);
        }

        return $this->saveProfile($request, $name, false);
    }

    /** Change a profile: only the fields sent change. */
    public function updateProfile(Request $request, string $name): JsonResponse
    {
        if (! $this->backup->isValidName($name)) {
            return response()->json(['error' => "Invalid profile name '{$name}'"], 422);
        }

        return $this->saveProfile($request, $name, true);
    }

    /**
     * Remove a profile. Its archives stay where they are; the message says
     * how many and for how long.
     */
    public function deleteProfile(string $name): JsonResponse
    {
        if (! $this->backup->isValidName($name)) {
            return response()->json(['error' => "Invalid profile name '{$name}'"], 422);
        }

        try {
            return response()->json(['data' => ['profile' => $name, 'removed' => true, 'message' => $this->backup->remove($name)]], 200);
        } catch (\RuntimeException $e) {
            return $this->cliError($e);
        }
    }

    public function enableProfile(string $name): JsonResponse
    {
        return $this->toggle($name, true);
    }

    public function disableProfile(string $name): JsonResponse
    {
        return $this->toggle($name, false);
    }

    /** List each S3 destination with its own credentials (or one, `name`). */
    public function testDestinations(Request $request): JsonResponse
    {
        $name = $request->input('name');
        $name = is_string($name) && $name !== '' ? $name : null;
        if ($name !== null && $name !== 's3' && ! $this->backup->isValidName($name)) {
            return response()->json(['error' => "Invalid destination name '{$name}'"], 422);
        }

        try {
            return response()->json(['data' => $this->backup->testDestinations($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    protected function toggle(string $name, bool $enabled): JsonResponse
    {
        if (! $this->backup->isValidName($name)) {
            return response()->json(['error' => "Invalid profile name '{$name}'"], 422);
        }

        try {
            $this->backup->toggle($name, $enabled);

            return response()->json(['data' => ['profile' => $name, 'enabled' => $enabled]], 200);
        } catch (\RuntimeException $e) {
            return $this->cliError($e);
        }
    }

    protected function saveProfile(Request $request, string $name, bool $isEdit): JsonResponse
    {
        $built = $this->backup->profileFlags($request->only(self::PROFILE_FIELDS), $isEdit);
        if ($built['errors'] !== []) {
            return response()->json(['error' => implode(' ', array_values($built['errors'])), 'errors' => $built['errors']], 422);
        }
        if ($isEdit && $built['flags'] === []) {
            return response()->json(['error' => 'Nothing to change'], 422);
        }

        try {
            $this->backup->saveProfile($name, $built['flags'], $isEdit);

            return response()->json(['data' => ['profile' => $name, 'saved' => true]], $isEdit ? 200 : 201);
        } catch (\RuntimeException $e) {
            return $this->cliError($e);
        }
    }

    /**
     * An older core is a 503; anything else is the CLI refusing the request
     * (an unknown profile, a destination that does not exist, a scope with
     * nothing to take), which is the caller's to fix: 422 with its message.
     */
    protected function cliError(\RuntimeException $e): JsonResponse
    {
        $message = $e->getMessage();
        $status = str_contains($message, 'needs Cipi ') ? 503 : 422;

        return response()->json(['error' => $message], $status);
    }
}

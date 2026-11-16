<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Exceptions\MysqlDatabaseListingUnavailableException;
use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiDatabaseEnginesCliService;
use CipiApi\Services\CipiCliService;
use CipiApi\Services\CipiDatabaseListCliService;
use CipiApi\Services\CipiDbUploadService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\CipiVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DbController extends Controller
{
    /** The core that knows `cipi db create --app=`, `cipi db link|unlink`. */
    public const LINK_MIN_CIPI = '5.9.0';

    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
        protected CipiDatabaseListCliService $dbListCli,
        protected CipiDatabaseEnginesCliService $dbEnginesCli,
        protected CipiDbUploadService $uploads,
        protected CipiVersionService $version,
        protected CipiCliService $cli,
    ) {}

    public function engines(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->dbEnginesCli->list()], 200);
        } catch (MysqlDatabaseListingUnavailableException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function list(Request $request): JsonResponse
    {
        $engine = $request->query('engine');
        if (is_string($engine) && $engine !== '') {
            if ($err = $this->validator->engineError($engine)) {
                return response()->json(['error' => $err], 422);
            }
            $engine = $this->validator->normalizeEngine($engine);
        } else {
            $engine = null;
        }

        try {
            return response()->json(['data' => $this->dbListCli->list($engine)], 200);
        } catch (MysqlDatabaseListingUnavailableException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'engine' => 'nullable|string',
            'app' => 'nullable|string',
        ]);

        $name = $validated['name'];
        if ($err = $this->validator->usernameError($name)) {
            return response()->json(['error' => $err], 422);
        }
        if ($err = $this->validator->engineError($validated['engine'] ?? null)) {
            return response()->json(['error' => $err], 422);
        }

        // `app`: show the database as that app's (Cipi CLI ≥ 5.9.0). An older
        // core would ignore the flag and create it unlinked — say so instead.
        $app = isset($validated['app']) && $validated['app'] !== '' ? (string) $validated['app'] : null;
        if ($app !== null) {
            if (! $this->validator->appExists($app)) {
                return response()->json(['error' => "App '{$app}' not found"], 404);
            }
            if ($gate = $this->linkGate()) {
                return $gate;
            }
        }

        $engine = $this->validator->normalizeEngine($validated['engine'] ?? null);
        $params = ['name' => $name];
        if ($engine !== null) {
            $params['engine'] = $engine;
        }
        if ($app !== null) {
            $params['app'] = $app;
        }

        if ($this->hasPendingDbCreate($name, $engine)) {
            return response()->json(['error' => "Database '{$name}' is already being created"], 409);
        }

        $command = 'db create --name=' . escapeshellarg($name);
        if ($engine !== null) {
            $command .= ' --engine=' . escapeshellarg($engine);
        }
        if ($app !== null) {
            $command .= ' --app=' . escapeshellarg($app);
        }
        $job = $this->jobs->dispatch('db-create', $command, $params);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function backup(Request $request, string $name): JsonResponse
    {
        $engine = $this->resolveEngineFromRequest($request);
        if ($engine instanceof JsonResponse) {
            return $engine;
        }

        $params = ['name' => $name];
        $command = 'db backup ' . escapeshellarg($name);
        if ($engine !== null) {
            $params['engine'] = $engine;
            $command .= ' --engine=' . escapeshellarg($engine);
        }
        $job = $this->jobs->dispatch('db-backup', $command, $params);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * Restore a database from a `.sql.gz` already on the server (`file`), or
     * from a dump uploaded through this API (`upload_id`, Cipi CLI ≥ 5.9.0 —
     * see {@see startUpload()}). The upload is deleted when the job ends,
     * whatever its result.
     */
    public function restore(Request $request, string $name): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required_without:upload_id|nullable|string|max:512',
            'upload_id' => 'required_without:file|nullable|string|max:64',
            'engine' => 'nullable|string',
        ]);
        if ($err = $this->validator->engineError($validated['engine'] ?? null)) {
            return response()->json(['error' => $err], 422);
        }
        $engine = $this->validator->normalizeEngine($validated['engine'] ?? null);

        $uploadId = isset($validated['upload_id']) && $validated['upload_id'] !== '' ? (string) $validated['upload_id'] : null;
        if ($uploadId !== null) {
            if (! empty($validated['file'])) {
                return response()->json(['error' => 'Give either file or upload_id, not both'], 422);
            }
            try {
                $this->uploads->requireCore();
            } catch (\RuntimeException $e) {
                return response()->json(['error' => $e->getMessage()], 503);
            }
            try {
                $file = $this->uploads->finalize($uploadId);
                $label = $this->uploads->name($uploadId);
            } catch (\OutOfBoundsException $e) {
                return response()->json(['error' => $e->getMessage()], 404);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            } catch (\RuntimeException $e) {
                return response()->json(['error' => $e->getMessage()], 500);
            }
            $params = ['name' => $name, 'file' => $label, 'upload_id' => $uploadId];
        } else {
            $file = (string) $validated['file'];
            if (! preg_match('/^[a-zA-Z0-9_\-\/\.]+\.sql\.gz$/', $file)) {
                return response()->json(['error' => 'Invalid backup file path. Must be a .sql.gz file with safe characters.'], 422);
            }
            $params = ['name' => $name, 'file' => $file];
        }

        $command = 'db restore ' . escapeshellarg($name) . ' ' . escapeshellarg($file) . ' --force';
        if ($engine !== null) {
            $params['engine'] = $engine;
            $command .= ' --engine=' . escapeshellarg($engine);
        }
        $job = $this->jobs->dispatch('db-restore', $command, $params);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * Limits for a dump upload: maximum size, chunk size, accepted extensions.
     */
    public function uploadLimits(): JsonResponse
    {
        try {
            $this->uploads->requireCore();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $this->uploads->limits()], 200);
    }

    /**
     * Start the upload of a dump: `filename` (.sql or .sql.gz) and its `size`
     * in bytes. Answers with the `upload_id` and the chunk size to send it in.
     * Then: POST …/uploads/{id}/chunks once per chunk, in order, and
     * POST /dbs/{name}/restore with `upload_id`.
     */
    public function startUpload(Request $request): JsonResponse
    {
        try {
            $this->uploads->requireCore();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        $validated = $request->validate([
            'filename' => 'required|string|max:255',
            'size' => 'required|integer|min:1',
        ]);

        try {
            return response()->json(['data' => $this->uploads->start($validated['filename'], (int) $validated['size'])], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 507);
        }
    }

    /**
     * One chunk of an upload (multipart: `index`, from 0, and the file
     * `chunk`). Chunks are appended in order; a wrong index is a 409 that
     * names the one expected.
     */
    public function uploadChunk(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'index' => 'required|integer|min:0',
            'chunk' => 'required|file',
        ]);

        try {
            return response()->json(['data' => $this->uploads->append($id, (int) $validated['index'], $request->file('chunk'))], 200);
        } catch (\OutOfBoundsException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 507);
        }
    }

    /** Cancel an upload and delete what arrived. */
    public function cancelUpload(string $id): JsonResponse
    {
        $this->uploads->discard($id);

        return response()->json(['data' => ['upload_id' => $id, 'cancelled' => true]], 200);
    }

    /**
     * Show a database as an app's (`cipi db link`, Cipi CLI ≥ 5.9.0): a label
     * in the app's entry — the database keeps its user and password, and
     * stays when the app is deleted. A database is linked to one app at most.
     */
    public function link(Request $request, string $name): JsonResponse
    {
        $validated = $request->validate([
            'app' => 'required|string',
            'engine' => 'nullable|string',
        ]);
        if (! $this->isDatabaseName($name)) {
            return response()->json(['error' => "Invalid database name '{$name}'"], 422);
        }
        if (! $this->validator->appExists($validated['app'])) {
            return response()->json(['error' => "App '{$validated['app']}' not found"], 404);
        }
        $engine = $this->resolveEngineFromRequest($request);
        if ($engine instanceof JsonResponse) {
            return $engine;
        }
        if ($gate = $this->linkGate()) {
            return $gate;
        }

        $command = 'db link ' . escapeshellarg($name) . ' ' . escapeshellarg($validated['app']);
        if ($engine !== null) {
            $command .= ' --engine=' . escapeshellarg($engine);
        }

        return $this->runLinkCommand($command, ['database' => $name, 'app' => $validated['app'], 'linked' => true]);
    }

    /** Remove the link between a database and its app (`cipi db unlink`). */
    public function unlink(Request $request, string $name): JsonResponse
    {
        if (! $this->isDatabaseName($name)) {
            return response()->json(['error' => "Invalid database name '{$name}'"], 422);
        }
        $engine = $this->resolveEngineFromRequest($request);
        if ($engine instanceof JsonResponse) {
            return $engine;
        }
        if ($gate = $this->linkGate()) {
            return $gate;
        }

        $command = 'db unlink ' . escapeshellarg($name);
        if ($engine !== null) {
            $command .= ' --engine=' . escapeshellarg($engine);
        }

        return $this->runLinkCommand($command, ['database' => $name, 'app' => null, 'linked' => false]);
    }

    public function password(Request $request, string $name): JsonResponse
    {
        $engine = $this->resolveEngineFromRequest($request);
        if ($engine instanceof JsonResponse) {
            return $engine;
        }

        $params = ['name' => $name];
        $command = 'db password ' . escapeshellarg($name);
        if ($engine !== null) {
            $params['engine'] = $engine;
            $command .= ' --engine=' . escapeshellarg($engine);
        }
        $job = $this->jobs->dispatch('db-password', $command, $params);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * Install a database engine (async). Requires Cipi CLI ≥ 5.0.6 sudoers.
     */
    public function installEngine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'engine' => 'required|string',
        ]);

        if ($err = $this->validator->engineError($validated['engine'])) {
            return response()->json(['error' => $err], 422);
        }

        $engine = $this->validator->normalizeEngine($validated['engine']);
        $job = $this->jobs->dispatch(
            'db-install',
            'db install ' . escapeshellarg($engine),
            ['engine' => $engine],
        );

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * @return string|null|JsonResponse Normalized engine, null when omitted, or 422 response.
     */
    protected function resolveEngineFromRequest(Request $request): string|null|JsonResponse
    {
        $raw = $request->input('engine', $request->query('engine'));
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if ($err = $this->validator->engineError($raw)) {
            return response()->json(['error' => $err], 422);
        }

        return $this->validator->normalizeEngine($raw);
    }

    /** A database name as the CLI accepts it (validate_db_name). */
    protected function isDatabaseName(string $name): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name);
    }

    protected function linkGate(): ?JsonResponse
    {
        try {
            $this->version->require(self::LINK_MIN_CIPI, 'Linking a database to an app');
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function runLinkCommand(string $command, array $data): JsonResponse
    {
        $result = $this->cli->run($command);
        $output = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $result['output'] ?? ''));
        if ($result['exit_code'] !== 0) {
            $message = preg_match('/\[ERROR\]\s*(.+)/', $output, $m) ? trim($m[1]) : ($output !== '' ? $output : 'cipi db link failed');

            return response()->json(['error' => $message], 422);
        }

        return response()->json(['data' => $data + ['message' => $output]], 200);
    }

    protected function hasPendingDbCreate(string $name, ?string $engine): bool
    {
        $query = CipiJob::where('type', 'db-create')
            ->whereIn('status', ['pending', 'running'])
            ->where('params->name', $name);

        if ($engine !== null) {
            $query->where('params->engine', $engine);
        }

        return $query->exists();
    }
}

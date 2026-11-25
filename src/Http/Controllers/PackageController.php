<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiPackageCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Optional host packages. `GET /api/packages` (`packages-view`) is the
 * catalog with its install state (Cipi CLI ≥ 5.2.2).
 *
 * `POST|DELETE /api/packages/{package}` (`packages-manage`, Cipi CLI ≥ 5.14.0)
 * install and remove one entry as jobs (`package-install`, `package-remove`):
 * apt takes from seconds to a few minutes. Only ids of the server's own
 * catalog are accepted — it is closed, and the CLI refuses anything else too.
 * The removal takes nothing another package needs (the CLI computes it).
 */
class PackageController extends Controller
{
    public function __construct(
        protected CipiPackageCliService $packages,
        protected CipiJobService $jobs,
    ) {}

    public function list(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->packages->list()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /** Install a catalog entry (`browsershot`, `ffmpeg`, `libreoffice`, …). A job. */
    public function install(string $package): JsonResponse
    {
        return $this->queue($package, 'package-install');
    }

    /** Remove a catalog entry, and only what nothing else needs. A job. */
    public function remove(string $package): JsonResponse
    {
        return $this->queue($package, 'package-remove');
    }

    protected function queue(string $package, string $type): JsonResponse
    {
        if (! CipiPackageCliService::validId($package)) {
            return response()->json(['error' => "'{$package}' is not an optional package"], 404);
        }

        try {
            $this->packages->requireManage();
            $entry = $this->packages->find($package);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        if ($entry === null) {
            return response()->json(['error' => "'{$package}' is not in this server's package catalog (cipi package list)"], 404);
        }

        if ($type === 'package-install' && $entry['installed']) {
            return response()->json(['error' => "{$package} is already installed"], 409);
        }
        if ($type === 'package-remove' && ! $entry['installed'] && ! $entry['partial']) {
            return response()->json(['error' => "{$package} is not installed"], 409);
        }

        $command = $type === 'package-install'
            ? CipiPackageCliService::installCommand($package)
            : CipiPackageCliService::removeCommand($package);
        $job = $this->jobs->dispatch($type, $command, ['package' => $package]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }
}

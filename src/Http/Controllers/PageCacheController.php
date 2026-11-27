<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiPageCacheService;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\PageCacheRefusedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The nginx page cache of an app (`cipi cache`, Cipi CLI ≥ 5.17.0 + API sudoers ≥ 5.17.0).
 *
 * `GET` reads it (`nginx-view`); `PUT` turns it on or off, `PUT …/exclude`
 * sets the paths of its own that are never kept and `POST …/clear` empties it
 * (`nginx-manage`). Synchronous: nginx is tested and reloaded
 * before the answer, and every change answers with the cache after it. What
 * the CLI or nginx refuses is a 422 with its words, and nothing has changed;
 * an older core is a 503 that names the version.
 */
class PageCacheController extends Controller
{
    public function __construct(
        protected CipiPageCacheService $cache,
        protected CipiValidationService $validator,
    ) {}

    public function list(): JsonResponse
    {
        return $this->respond(fn () => $this->cache->list());
    }

    public function show(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        return $this->respond(fn () => $this->cache->show($name));
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $input = $request->validate(CipiPageCacheService::RULES);
        if ($err = $this->cache->inputError($input)) {
            return response()->json(['error' => $err], 422);
        }
        if (($input['enabled'] ?? false) && $this->validator->isNodeApp($name)) {
            return response()->json(['error' => "'{$name}' is a Node app: its pages come from its own server, and the page cache is written for PHP apps"], 422);
        }

        return $this->respond(fn () => $this->cache->update($name, $input));
    }

    /** The paths of its own the app keeps out of its cache: the whole list. */
    public function exclude(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $input = $request->validate(CipiPageCacheService::EXCLUDE_RULES);
        if ($err = CipiPageCacheService::excludeError($input['exclude'])) {
            return response()->json(['error' => $err], 422);
        }
        if ($this->validator->isNodeApp($name)) {
            return response()->json(['error' => "'{$name}' is a Node app: its pages come from its own server, and the page cache is written for PHP apps"], 422);
        }

        return $this->respond(fn () => $this->cache->exclude($name, array_values($input['exclude'])));
    }

    public function clear(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        return $this->respond(fn () => $this->cache->clear($name));
    }

    /** POST /apps/{name}/cache/purge — some pages: {"paths": ["/blog/post/", "/blog/*"]}. */
    public function purge(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        $input = $request->validate(CipiPageCacheService::PURGE_RULES);

        return $this->respond(fn () => $this->cache->purge($name, array_values($input['paths'])));
    }

    /** @param  callable(): array  $action */
    protected function respond(callable $action): JsonResponse
    {
        try {
            return response()->json(['data' => $action()], 200);
        } catch (PageCacheRefusedException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}

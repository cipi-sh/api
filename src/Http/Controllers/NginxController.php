<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiNginxCliService;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\NginxConfigRefusedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * An app's own nginx directives (`cipi nginx`, Cipi CLI ≥ 5.14.0 + API sudoers ≥ 5.14.0).
 *
 * `GET` reads them (`nginx-view`), `PUT` replaces them and `DELETE` removes
 * them (`nginx-manage`). Synchronous: nginx is tested and reloaded before the
 * answer. A text the CLI or nginx refuses is a 422 with the reasons, and
 * nothing has changed. The CLI takes a subset of the language from the API —
 * {@see CipiNginxCliService}.
 */
class NginxController extends Controller
{
    public function __construct(
        protected CipiNginxCliService $nginx,
        protected CipiValidationService $validator,
    ) {}

    public function show(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        try {
            return response()->json(['data' => $this->nginx->show($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'config' => 'present|nullable|string|max:' . CipiNginxCliService::MAX_BYTES,
        ]);
        $config = (string) ($validated['config'] ?? '');
        if (strlen($config) > CipiNginxCliService::MAX_BYTES) {
            return response()->json(['error' => 'The directives are too long (32 KB at most).'], 422);
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $config)) {
            return response()->json(['error' => 'The directives hold a control character.'], 422);
        }

        return $this->apply(fn () => $this->nginx->update($name, $config));
    }

    public function destroy(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        return $this->apply(fn () => $this->nginx->reset($name));
    }

    /** @param  callable(): array  $change */
    protected function apply(callable $change): JsonResponse
    {
        try {
            return response()->json(['data' => $change()], 200);
        } catch (NginxConfigRefusedException $e) {
            return response()->json(['error' => $e->getMessage(), 'reasons' => $e->reasons()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}

<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiAppEnvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EnvController extends Controller
{
    public function __construct(
        protected CipiAppEnvService $env,
    ) {}

    public function show(string $name): JsonResponse
    {
        try {
            return response()->json(['data' => $this->env->show($name)], 200);
        } catch (\RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    public function update(Request $request, string $name): JsonResponse
    {
        $validated = $request->validate([
            'set' => 'nullable|array',
            'set.*' => 'nullable|string',
            'unset' => 'nullable|array',
            'unset.*' => 'string',
            'order' => 'nullable|array',
            'order.*' => 'string',
        ]);

        $set = $validated['set'] ?? [];
        $unset = $validated['unset'] ?? [];
        $order = isset($validated['order']) ? array_values($validated['order']) : null;

        try {
            return response()->json(['data' => $this->env->update($name, $set, $unset, $order)], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    /** GET /apps/{name}/env/raw — the .env as it is. */
    public function raw(string $name): JsonResponse
    {
        try {
            return response()->json(['data' => $this->env->raw($name)], 200);
        } catch (\RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    /**
     * PUT /apps/{name}/env/raw — {"text": "…"}: the whole .env, written as it
     * is. The text is read from the request's own body, so no middleware
     * (TrimStrings, ConvertEmptyStringsToNull) changes a byte of it.
     */
    public function updateRaw(Request $request, string $name): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $text = is_array($body) && array_key_exists('text', $body) ? $body['text'] : $request->input('text');
        if (! is_string($text) || $text === '') {
            return response()->json(['error' => 'text is required: the whole .env, as a string'], 422);
        }

        try {
            return response()->json(['data' => $this->env->replaceRaw($name, $text)], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    protected function runtimeError(\RuntimeException $e): JsonResponse
    {
        if ($e instanceof \CipiApi\Services\EnvRawUnsupportedException) {
            return response()->json(['error' => $e->getMessage()], 501);
        }

        $msg = $e->getMessage();
        if (str_contains($msg, 'not found') || str_contains($msg, 'custom app')) {
            $status = str_contains($msg, 'custom app') ? 422 : 404;

            return response()->json(['error' => $msg], $status);
        }

        return response()->json(['error' => $msg], 503);
    }
}

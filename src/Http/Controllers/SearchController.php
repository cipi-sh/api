<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiDatabaseListCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SearchController extends Controller
{
    public function __construct(
        protected CipiValidationService $validator,
        protected CipiDatabaseListCliService $dbListCli,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return response()->json(['error' => 'Query parameter "q" is required'], 422);
        }
        if (strlen($query) > 128) {
            return response()->json(['error' => 'Query too long (max 128 chars)'], 422);
        }

        $needle = mb_strtolower($query);
        $apps = [];
        $aliases = [];
        $databases = [];

        foreach ($this->validator->getApps() as $name => $app) {
            $primaryDomain = (string) ($app['domain'] ?? '');
            $appAliases = (array) ($app['aliases'] ?? []);
            $haystack = mb_strtolower($name . ' ' . $primaryDomain . ' ' . implode(' ', $appAliases));

            if (str_contains($haystack, $needle)) {
                $apps[] = [
                    'app' => $name,
                    'domain' => $primaryDomain,
                    'aliases' => $appAliases,
                    'php' => $app['php'] ?? null,
                ];
            }

            foreach ($appAliases as $alias) {
                if (str_contains(mb_strtolower((string) $alias), $needle)) {
                    $aliases[] = ['app' => $name, 'alias' => (string) $alias];
                }
            }
        }

        try {
            foreach ((array) $this->dbListCli->list() as $db) {
                if (str_contains(mb_strtolower((string) ($db['name'] ?? '')), $needle)) {
                    $databases[] = $db;
                }
            }
        } catch (\Throwable $e) {
            // Database listing optional; fall back silently for search.
        }

        return response()->json([
            'data' => [
                'apps' => $apps,
                'aliases' => $aliases,
                'databases' => $databases,
            ],
            'query' => $query,
            'count' => count($apps) + count($aliases) + count($databases),
        ], 200);
    }
}

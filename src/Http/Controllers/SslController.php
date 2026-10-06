<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiSslDnsCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SslController extends Controller
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
        protected CipiSslDnsCliService $dns,
    ) {}

    /**
     * Issue / reissue the app certificate. Optional body (Cipi CLI ≥ 5.5.0):
     * `dns` (cloudflare) + `account` for DNS-01, `wildcard` true/false to add or drop
     * `*.<apex>`, `http` to go back to HTTP-01. Without a body the CLI reissues a DNS-01
     * certificate over DNS-01 with the account it was issued with (Cipi ≥ 5.5.1).
     */
    public function install(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'dns' => 'nullable|string|in:cloudflare',
            'account' => ['nullable', 'string', 'regex:' . CipiSslDnsCliService::ACCOUNT_PATTERN],
            'wildcard' => 'nullable|boolean',
            'http' => 'nullable|boolean',
        ]);

        $dns = $validated['dns'] ?? null;
        $account = ($validated['account'] ?? '') !== '' ? $validated['account'] : null;
        $wildcard = isset($validated['wildcard']) ? (bool) $validated['wildcard'] : null;
        $http = (bool) ($validated['http'] ?? false);
        if ($err = $this->dns->installOptionsError($dns, $account, $wildcard, $http)) {
            return response()->json(['error' => $err], 422);
        }

        $command = $this->dns->installCommand($name, $dns, $account, $wildcard, $http);
        $params = array_filter(
            ['app' => $name, 'dns' => $dns, 'account' => $account, 'wildcard' => $wildcard, 'http' => $http ?: null],
            fn ($v) => $v !== null,
        );
        $job = $this->jobs->dispatch('ssl-install', $command, $params);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function force(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $command = 'ssl force ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('ssl-force', $command, ['app' => $name]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * Cloudflare accounts for DNS-01 and the certificates that renew with each (tokens never shown).
     */
    public function dnsList(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->dns->accounts()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * Add a Cloudflare account, or rotate its token. Synchronous: the token is never queued.
     */
    public function dnsSet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'regex:' . CipiSslDnsCliService::ACCOUNT_PATTERN],
            'token' => ['required', 'string', 'regex:' . CipiSslDnsCliService::TOKEN_PATTERN],
        ]);

        try {
            $message = $this->dns->set($validated['name'] ?? 'default', $validated['token']);

            return response()->json(['data' => $this->dns->accounts(), 'message' => $message], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Remove a Cloudflare account. Refused (409) while a certificate still renews with it.
     */
    public function dnsRemove(string $account): JsonResponse
    {
        if (! preg_match(CipiSslDnsCliService::ACCOUNT_PATTERN, $account)) {
            return response()->json(['error' => "Invalid account name '{$account}'"], 422);
        }

        try {
            $accounts = $this->dns->accounts();
            $row = collect($accounts)->firstWhere('account', $account);
            if ($row === null) {
                return response()->json(['error' => "Cloudflare account '{$account}' is not configured"], 404);
            }
            if ($row['certificates'] !== []) {
                return response()->json([
                    'error' => "Account '{$account}' is still used to renew: " . implode(', ', $row['certificates'])
                        . '. Reissue those certificates with another account first.',
                ], 409);
            }

            $message = $this->dns->remove($account);

            return response()->json(['data' => $this->dns->accounts(), 'message' => $message], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}

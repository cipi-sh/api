<?php

namespace CipiApi\Services;

class CipiValidationService
{
    public function getApps(): array
    {
        $path = config('cipi.apps_json', '/etc/cipi/apps.json');
        if (! file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?: [];
    }

    public function appExists(string $name): bool
    {
        return array_key_exists($name, $this->getApps());
    }

    public function isValidUsername(string $name): bool
    {
        return $this->usernameError($name) === null;
    }

    public function usernameError(string $name): ?string
    {
        if (strlen($name) < 3 || strlen($name) > 32) {
            return 'Username must be 3-32 characters long';
        }
        if (! preg_match('/^[a-z][a-z0-9]*$/', $name)) {
            return 'Username must start with a lowercase letter and contain only lowercase letters and numbers';
        }
        $reserved = config('cipi.reserved_usernames', []);
        if (in_array($name, $reserved, true)) {
            return "Username '{$name}' is reserved by the system";
        }
        return null;
    }

    public function isValidDomain(string $domain): bool
    {
        return $this->domainError($domain) === null;
    }

    public function domainError(string $domain): ?string
    {
        if (strlen($domain) === 0) {
            return 'Domain is required';
        }
        if (strlen($domain) > 253) {
            return 'Domain must be at most 253 characters';
        }
        if (! preg_match(
            '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/',
            $domain
        )) {
            return "Invalid domain format '{$domain}'. Must be a valid FQDN (e.g. app.example.com)";
        }
        return null;
    }

    public function isValidPhpVersion(?string $version): bool
    {
        return $this->phpVersionError($version) === null;
    }

    public function phpVersionError(?string $version): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }
        $allowed = config('cipi.php_versions', []);
        if (! in_array($version, $allowed, true)) {
            return "Invalid PHP version '{$version}'. Allowed: " . implode(', ', $allowed);
        }
        return null;
    }

    /**
     * Returns the app name using this domain, or null if free.
     */
    public function domainUsedBy(string $domain, ?string $excludeApp = null): ?string
    {
        foreach ($this->getApps() as $appName => $app) {
            if ($excludeApp && $appName === $excludeApp) {
                continue;
            }
            if (($app['domain'] ?? '') === $domain) {
                return $appName;
            }
            if (in_array($domain, $app['aliases'] ?? [], true)) {
                return $appName;
            }
        }
        return null;
    }

    public function getAppAliases(string $name): array
    {
        $apps = $this->getApps();
        return $apps[$name]['aliases'] ?? [];
    }

    public function getAppDomain(string $name): ?string
    {
        $apps = $this->getApps();
        return $apps[$name]['domain'] ?? null;
    }
}

<?php

namespace CipiApi\Services;

class CipiOutputParser
{
    public function parse(string $type, string $output, bool $success = true): ?array
    {
        $plain = $this->stripAnsi($output);

        $result = match ($type) {
            'app-create' => $this->parseAppCreate($plain),
            'app-edit' => $this->parseAppEdit($plain),
            'app-delete' => $this->parseAppDelete($plain),
            'app-deploy' => $this->parseAppDeploy($plain),
            'app-deploy-rollback' => $this->parseAppDeployRollback($plain),
            'app-deploy-unlock' => $this->parseAppDeployUnlock($plain),
            'alias-create' => $this->parseAliasCreate($plain),
            'alias-delete' => $this->parseAliasDelete($plain),
            'ssl-install' => $this->parseSslInstall($plain),
            'db-create' => $this->parseDbCreate($plain),
            'db-list' => $this->parseDbList($plain),
            'db-delete' => $this->parseDbDelete($plain),
            'db-backup' => $this->parseDbBackup($plain),
            'db-restore' => $this->parseDbRestore($plain),
            'db-password' => $this->parseDbPassword($plain),
            default => null,
        };

        if (! $success && $result === null) {
            $result = ['error' => $this->extractErrorMessage($plain)];
        }

        return $result;
    }

    protected function extractErrorMessage(string $text): ?string
    {
        if (preg_match('/\[ERROR\]\s*(.+?)(?:\n|$)/', $text, $m)) {
            return trim($m[1]);
        }
        $lines = array_filter(explode("\n", $text), fn ($l) => trim($l) !== '');
        $last = end($lines);
        return $last ? trim($last) : null;
    }

    public function stripAnsi(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $text);
    }

    protected function extractLabel(string $text, string $label): ?string
    {
        if (preg_match('/' . preg_quote($label, '/') . '\s*([^\n]+)/', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function parseAppCreate(string $text): ?array
    {
        $app = null;
        if (preg_match('/APP\s+CREATED:\s*(\S+)/', $text, $m)) {
            $app = trim($m[1]);
        }
        if (! $app) {
            return null;
        }

        $domain = $this->extractLabel($text, 'Domain:');
        $php = $this->extractLabel($text, 'PHP:');
        $home = $this->extractLabel($text, 'Home:');

        $sshUser = $sshPassword = null;
        if (preg_match('/SSH\s+(\S+)\s*\/\s*(\S+)/', $text, $m)) {
            $sshUser = $m[1];
            $sshPassword = $m[2];
        }

        $dbUser = $dbPassword = null;
        if (preg_match('/Database\s+(\S+)\s*\/\s*(\S+)/', $text, $m)) {
            $dbUser = $m[1];
            $dbPassword = $m[2];
        }

        $deployKey = null;
        if (preg_match('/(ssh-(?:ed25519|rsa)\s+[A-Za-z0-9+\/=]+\s+\S+)/', $text, $m)) {
            $deployKey = trim($m[1]);
        }

        $webhook = $this->extractLabel($text, 'Webhook');
        if (! $webhook && $domain) {
            $webhook = "https://{$domain}/cipi/webhook";
        }

        $token = $this->extractLabel($text, 'Token');

        return array_filter([
            'app' => $app,
            'domain' => $domain,
            'php' => $php,
            'home' => $home,
            'ssh' => ($sshUser || $sshPassword) ? [
                'user' => $sshUser,
                'password' => $sshPassword,
            ] : null,
            'database' => ($dbUser || $dbPassword) ? [
                'user' => $dbUser,
                'password' => $dbPassword,
            ] : null,
            'deploy_key' => $deployKey,
            'webhook' => $webhook,
            'webhook_token' => $token,
        ], fn ($v) => $v !== null);
    }

    protected function parseAppEdit(string $text): ?array
    {
        $changes = [];
        if (preg_match_all('/✓\s+(.+?)(?:\n|$)/', $text, $matches)) {
            foreach ($matches[1] as $m) {
                $m = trim($m);
                if (preg_match('/^(\w+)\s*→\s*(.+)$/', $m, $c)) {
                    $changes[strtolower(trim($c[1]))] = trim($c[2]);
                } elseif ($m === 'Repository updated') {
                    $changes['repository'] = 'updated';
                }
            }
        }
        if (str_contains($text, 'Nothing changed')) {
            return ['changes' => [], 'message' => 'Nothing changed'];
        }
        return ! empty($changes) ? ['changes' => $changes] : null;
    }

    protected function parseAppDelete(string $text): ?array
    {
        if (preg_match("/'([^']+)'\s+deleted/", $text, $m)) {
            return ['app' => $m[1], 'deleted' => true];
        }
        return null;
    }

    protected function parseAppDeploy(string $text): ?array
    {
        if (preg_match('/deployed\s+successfully/i', $text)) {
            $app = null;
            if (preg_match("/'([^']+)'\s+deployed/i", $text, $m)) {
                $app = $m[1];
            }

            return array_filter([
                'app' => $app,
                'deployed' => true,
            ], fn ($v) => $v !== null);
        }

        return null;
    }

    protected function parseAppDeployRollback(string $text): ?array
    {
        if (preg_match('/rollback\s+completed/i', $text) || preg_match('/rolled\s+back/i', $text)) {
            $app = null;
            if (preg_match("/'([^']+)'\s+rolled\s+back/i", $text, $m)) {
                $app = $m[1];
            }

            return array_filter([
                'app' => $app,
                'rolled_back' => true,
            ], fn ($v) => $v !== null);
        }

        return null;
    }

    protected function parseAppDeployUnlock(string $text): ?array
    {
        if (preg_match('/deploy\s+unlocked/i', $text) || preg_match('/unlock\s+completed/i', $text)) {
            $app = null;
            if (preg_match("/'([^']+)'\s+(?:deploy\s+)?unlocked/i", $text, $m)) {
                $app = $m[1];
            }

            return array_filter([
                'app' => $app,
                'unlocked' => true,
            ], fn ($v) => $v !== null);
        }

        return null;
    }

    protected function parseAliasCreate(string $text): ?array
    {
        if (preg_match("/'([^']+)'\s+added\s+to\s+'([^']+)'/", $text, $m)) {
            return ['alias' => $m[1], 'app' => $m[2]];
        }
        return null;
    }

    protected function parseAliasDelete(string $text): ?array
    {
        if (preg_match("/'([^']+)'\s+removed\s+from\s+'([^']+)'/", $text, $m)) {
            return ['alias' => $m[1], 'app' => $m[2]];
        }
        return null;
    }

    protected function parseSslInstall(string $text): ?array
    {
        if (preg_match('/SSL\s+installed\s+for\s+(\S+)/', $text, $m)) {
            return ['domain' => trim($m[1]), 'installed' => true];
        }
        return null;
    }

    protected function parseDbCreate(string $text): ?array
    {
        $name = $this->extractLabel($text, 'Database:') ?? $this->extractLabel($text, 'Name:');
        $user = $this->extractLabel($text, 'Username:') ?? $this->extractLabel($text, 'User:');
        $password = $this->extractLabel($text, 'Password:');
        $url = $this->extractLabel($text, 'URL:');

        if (! $name && ! $user) {
            return null;
        }

        return array_filter([
            'database' => $name,
            'user' => $user,
            'password' => $password,
            'url' => $url,
        ], fn ($v) => $v !== null);
    }

    protected function parseDbList(string $text): ?array
    {
        $databases = [];
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '─') || str_starts_with($line, '=') || str_contains($line, 'DATABASE') || str_contains($line, 'Name')) {
                continue;
            }
            if (preg_match('/^(\S+)\s+(.+)$/', $line, $m)) {
                $databases[] = ['name' => trim($m[1]), 'size' => trim($m[2])];
            } elseif (preg_match('/^(\S+)$/', $line, $m)) {
                $databases[] = ['name' => trim($m[1])];
            }
        }

        return ['databases' => $databases];
    }

    protected function parseDbDelete(string $text): ?array
    {
        if (preg_match("/'([^']+)'\s+deleted/i", $text, $m)) {
            return ['database' => $m[1], 'deleted' => true];
        }
        if (preg_match('/deleted\s+(?:database\s+)?(\S+)/i', $text, $m)) {
            return ['database' => trim($m[1]), 'deleted' => true];
        }
        return null;
    }

    protected function parseDbBackup(string $text): ?array
    {
        $file = $this->extractLabel($text, 'Backup:') ?? $this->extractLabel($text, 'File:');
        if (! $file && preg_match('/([\/\w\-\.]+\.sql\.gz)/', $text, $m)) {
            $file = trim($m[1]);
        }

        if ($file) {
            return ['file' => $file];
        }

        if (preg_match('/backup\s+completed/i', $text)) {
            return ['backed_up' => true];
        }

        return null;
    }

    protected function parseDbRestore(string $text): ?array
    {
        if (preg_match('/restore[d]?\s+(?:completed|successfully)/i', $text)) {
            $name = null;
            if (preg_match("/'([^']+)'\s+restored/i", $text, $m)) {
                $name = $m[1];
            }
            return array_filter([
                'database' => $name,
                'restored' => true,
            ], fn ($v) => $v !== null);
        }
        return null;
    }

    protected function parseDbPassword(string $text): ?array
    {
        $password = $this->extractLabel($text, 'Password:') ?? $this->extractLabel($text, 'New password:');
        $user = $this->extractLabel($text, 'Username:') ?? $this->extractLabel($text, 'User:');

        if ($password) {
            return array_filter([
                'user' => $user,
                'password' => $password,
            ], fn ($v) => $v !== null);
        }

        if (preg_match('/password\s+(?:updated|changed|regenerated)/i', $text)) {
            return ['password_changed' => true];
        }

        return null;
    }
}

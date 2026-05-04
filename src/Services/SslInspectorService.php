<?php

namespace CipiApi\Services;

use Illuminate\Support\Facades\Cache;

class SslInspectorService
{
    public function inspect(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $ttl = (int) config('cipi.ssl.cache_ttl', 300);
        $cacheKey = 'cipi.ssl.' . md5($domain);

        if ($ttl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $info = $this->fetchCertificate($domain);
        if (! $info) {
            return null;
        }

        if ($ttl > 0) {
            Cache::put($cacheKey, $info, $ttl);
        }
        return $info;
    }

    public function clearCache(string $domain): void
    {
        Cache::forget('cipi.ssl.' . md5(strtolower(trim($domain))));
    }

    protected function fetchCertificate(string $domain): ?array
    {
        $host = (string) config('cipi.ssl.host', '127.0.0.1');
        $port = (int) config('cipi.ssl.port', 443);
        $timeout = max(1, (int) config('cipi.ssl.connect_timeout', 5));

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $domain,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! $client) {
            return [
                'domain' => $domain,
                'available' => false,
                'error' => trim($errstr) !== '' ? trim($errstr) : "Unable to connect to {$host}:{$port}",
            ];
        }

        $params = stream_context_get_params($client);
        @fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! $cert) {
            return [
                'domain' => $domain,
                'available' => false,
                'error' => 'No certificate captured during TLS handshake',
            ];
        }

        $parsed = openssl_x509_parse($cert);
        if (! $parsed) {
            return [
                'domain' => $domain,
                'available' => false,
                'error' => 'Unable to parse X.509 certificate',
            ];
        }

        $san = [];
        $altNames = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        if ($altNames !== '') {
            foreach (preg_split('/,\s*/', $altNames) as $entry) {
                if (str_starts_with($entry, 'DNS:')) {
                    $san[] = substr($entry, 4);
                }
            }
        }

        $validFrom = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $validUntil = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $now = time();
        $daysRemaining = $validUntil ? (int) floor(($validUntil - $now) / 86400) : null;

        $issuer = '';
        if (isset($parsed['issuer']['O'])) {
            $issuer = (string) $parsed['issuer']['O'];
        } elseif (isset($parsed['issuer']['CN'])) {
            $issuer = (string) $parsed['issuer']['CN'];
        }

        return [
            'domain' => $domain,
            'available' => true,
            'subject' => (string) ($parsed['subject']['CN'] ?? $domain),
            'issuer' => $issuer,
            'serial' => (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            'valid_from' => $validFrom ? gmdate('c', $validFrom) : null,
            'valid_until' => $validUntil ? gmdate('c', $validUntil) : null,
            'days_remaining' => $daysRemaining,
            'expired' => $validUntil ? ($validUntil < $now) : null,
            'san' => array_values(array_unique($san)),
            'self_signed' => $this->isSelfSigned($parsed),
        ];
    }

    protected function isSelfSigned(array $parsed): bool
    {
        $issuer = $parsed['issuer'] ?? [];
        $subject = $parsed['subject'] ?? [];
        return ($issuer['CN'] ?? null) !== null
            && ($issuer['CN'] ?? null) === ($subject['CN'] ?? null)
            && ($issuer['O'] ?? null) === ($subject['O'] ?? null);
    }
}

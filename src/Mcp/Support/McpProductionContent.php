<?php

namespace CipiApi\Mcp\Support;

/**
 * Redacts common secrets in MCP responses and prepends production-content warnings.
 */
final class McpProductionContent
{
    public const LOG_WARNING = '⚠️ You are about to send production logs to the model. They may include personal data or secrets.';

    public const HIGH_RISK_ALERT = '⚠️ WARNING: High-risk patterns detected (token, payment card data, or connection string). Review the content before using it.';

    public static function formatLogResponse(string $content): string
    {
        return self::wrap($content, includeLogWarning: true);
    }

    public static function formatSensitiveResponse(string $content): string
    {
        return self::wrap($content, includeLogWarning: false);
    }

    private static function wrap(string $content, bool $includeLogWarning): string
    {
        $redacted = self::redact($content);
        $parts = [];

        if ($includeLogWarning) {
            $parts[] = self::LOG_WARNING;
        }

        if (self::hasHighRiskPatterns($redacted)) {
            $parts[] = self::HIGH_RISK_ALERT;
        }

        $parts[] = $redacted;

        return implode("\n\n", $parts);
    }

    public static function redact(string $content): string
    {
        $content = preg_replace(
            '/\b(DB_PASSWORD|DATABASE_PASSWORD|APP_KEY|AWS_SECRET_ACCESS_KEY|MAIL_PASSWORD|REDIS_PASSWORD|SECRET_KEY|API_KEY|AUTH_TOKEN)\s*=\s*\S+/i',
            '$1=[REDACTED]',
            $content,
        ) ?? $content;

        $content = preg_replace(
            '/\b(Password|New password|password|passwd|Token|Webhook token)\s*[:=]\s*\S+/i',
            '$1: [REDACTED]',
            $content,
        ) ?? $content;

        $content = preg_replace(
            '/\bBearer\s+\S+/i',
            'Bearer [REDACTED]',
            $content,
        ) ?? $content;

        $content = preg_replace(
            '/\bAuthorization\s*:\s*\S+/i',
            'Authorization: [REDACTED]',
            $content,
        ) ?? $content;

        $content = preg_replace(
            '/\b(SSH|Database)\s+(\S+)\s*\/\s*(\S+)/i',
            '$1 $2 / [REDACTED]',
            $content,
        ) ?? $content;

        return $content;
    }

    public static function hasHighRiskPatterns(string $content): bool
    {
        $patterns = [
            '/(?:mysql|postgres|postgresql|mongodb|redis|amqp):\/\/[^\s\'"]+/i',
            '/\b(?:\d{4}[-\s]?){3}\d{4}\b/',
            '/\b(?:sk-[a-zA-Z0-9]{20,}|ghp_[a-zA-Z0-9]{36,}|gho_[a-zA-Z0-9]{36,}|xox[baprs]-[a-zA-Z0-9-]{10,}|AKIA[A-Z0-9]{16})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }
}

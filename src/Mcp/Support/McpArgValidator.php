<?php

namespace CipiApi\Mcp\Support;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class McpArgValidator
{
    /**
     * @return array{0: ?string, 1: ?Response}
     */
    public static function requiredString(Request $request, string $key, ?string $label = null): array
    {
        $value = $request->get($key);
        if (! is_string($value) || trim($value) === '') {
            $label ??= $key;

            return [null, Response::text("Error: {$label} is required")];
        }

        return [trim($value), null];
    }
}

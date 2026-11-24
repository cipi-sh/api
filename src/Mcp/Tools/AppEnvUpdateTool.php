<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Mcp\Support\McpProductionContent;
use CipiApi\Services\CipiAppEnvService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Merge Laravel app .env keys (set/unset) and optionally reorder them (order: every key once, Cipi 5.13.0+). Same as PUT /api/apps/{name}/env. Custom apps unsupported. Secrets are redacted.')]
class AppEnvUpdateTool extends Tool
{
    public function __construct(
        protected CipiAppEnvService $env,
    ) {}

    public function handle(Request $request): Response
    {
        [$name, $error] = McpArgValidator::requiredString($request, 'name');
        if ($error !== null) {
            return $error;
        }

        $setRaw = $request->get('set');
        $unsetRaw = $request->get('unset');

        $set = [];
        if (is_string($setRaw) && $setRaw !== '') {
            $decoded = json_decode($setRaw, true);
            if (! is_array($decoded)) {
                return Response::text('Error: set must be a JSON object string, e.g. {"MAIL_HOST":"smtp.example.com"}');
            }
            $set = $decoded;
        } elseif (is_array($setRaw)) {
            $set = $setRaw;
        }

        $unset = [];
        if (is_string($unsetRaw) && $unsetRaw !== '') {
            $decoded = json_decode($unsetRaw, true);
            if (! is_array($decoded)) {
                return Response::text('Error: unset must be a JSON array string, e.g. ["OLD_KEY"]');
            }
            $unset = $decoded;
        } elseif (is_array($unsetRaw)) {
            $unset = $unsetRaw;
        }

        $order = null;
        $orderRaw = $request->get('order');
        if (is_string($orderRaw) && $orderRaw !== '') {
            $decoded = json_decode($orderRaw, true);
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                return Response::text('Error: order must be a JSON array string of every key, e.g. ["APP_NAME","APP_ENV"]');
            }
            $order = $decoded;
        } elseif (is_array($orderRaw)) {
            $order = array_values($orderRaw);
        }

        try {
            $data = $this->env->update($name, $set, $unset, $order);
            $json = json_encode($data['vars'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

            return Response::text(McpProductionContent::formatSensitiveResponse(
                ".env updated for '{$name}':\n\n{$json}"
            ));
        } catch (\InvalidArgumentException $e) {
            return Response::text('Error: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'set' => $schema->string()->description('JSON object of keys to set, e.g. {"FOO":"bar"}'),
            'unset' => $schema->string()->description('JSON array of keys to remove, e.g. ["OLD_KEY"]'),
            'order' => $schema->string()->description('JSON array of every key of the .env, in the order wanted (applied after set/unset), e.g. ["APP_NAME","APP_ENV","APP_KEY"]'),
        ];
    }
}

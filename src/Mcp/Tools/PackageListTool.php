<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiPackageCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Optional host packages catalog with install state (cipi package list): image-optimizers, avif, ffmpeg, imagemagick, poppler-utils, pdftk, libreoffice, pandoc, tesseract (tools), browsershot and fonts-cjk (libraries and fonts; Chrome itself comes with Puppeteer in the app), and the sec-* security scanners Hackly runs (sec-recon, sec-gitleaks; upstream releases pinned by version and SHA-256: sec-nuclei, sec-zap, sec-nikto, sec-wpscan, sec-trivy, sec-semgrep, sec-checkov). kind, binaries, missing, source, version, installed_version and outdated per entry on Cipi 5.14.0+. Read-only here: installing and removing are POST|DELETE /api/packages/{id} (packages-manage) or cipi package install|remove on the host. Requires Cipi 5.2.2+.')]
#[IsReadOnly]
class PackageListTool extends Tool
{
    public function __construct(
        protected CipiPackageCliService $packages,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->packages->list(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-03-05

### Added

- REST API for Cipi server control panel
  - Apps: list, show, create, edit, delete
  - Aliases: list, create, delete
  - SSL: install certificate
  - Jobs: show async job status
- MCP server endpoint at `/mcp` (requires `laravel/mcp` package)
- Swagger documentation at `/docs`
- Laravel Sanctum authentication with token abilities
- Artisan commands: `cipi:token-create`, `cipi:token-list`, `cipi:token-revoke`, `cipi:seed-api-user`
- Queue-based job execution for long-running Cipi operations

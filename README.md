# Cipi API

Laravel package that exposes a REST API, an MCP server, and Swagger documentation for the [Cipi](https://cipi.sh) server control panel.

## Requirements

- PHP 8.2+
- Laravel 12+

## Installation

```bash
composer require andreapollastri/cipi-api
```

Publish the configuration and assets:

```bash
php artisan vendor:publish --tag=cipi-config
php artisan vendor:publish --tag=cipi-assets
php artisan migrate
```

Seed the API user and create a token:

```bash
php artisan cipi:seed-api-user
php artisan cipi:token-create
```

## Features

- **REST API** — CRUD for apps, aliases, SSL, and async jobs (`/api/*`), secured with Laravel Sanctum and token abilities.
- **MCP Server** — Model Context Protocol endpoint at `/mcp` for AI-powered integrations.
- **Swagger Docs** — Interactive API reference available at `/docs`.
- **Artisan Commands** — `cipi:token-create`, `cipi:token-list`, `cipi:token-revoke`.

## Configuration

Set `CIPI_APPS_JSON` in `.env` (default: `/etc/cipi/apps.json`). The API must be able to read this file.

**If you get "App not found" but the app exists in `cipi app list`:** the web server user cannot read `apps.json`. Either:

- `chmod 644 /etc/cipi/apps.json` (makes it world-readable), or
- Add to sudoers: `<api-user> ALL=(ALL) NOPASSWD: /usr/bin/cat /etc/cipi/apps.json`

Replace `<api-user>` with the PHP-FPM user (e.g. `www-data` or the app username).

## License

MIT

# Changelog

All notable changes to this project will be documented in this file.

## [1.7.0] - 2026-05-04

Mobile-app friendly surface area: server telemetry, device registration for push, deploy history and live log tailing, SSL inspection, activity timeline, global search, and a public health probe.

### Added

- **Public health probe `GET /api/ping`:** unauthenticated endpoint returning `{ cipi: true, version, time }`. Lets mobile apps validate that a URL belongs to a Cipi server during onboarding before asking the user for a token.
- **Server telemetry**
  - `GET /api/server/status` — instant snapshot of CPU (cores, load avg, usage %), memory, swap, disks, uptime, OS, kernel, Cipi version, and `systemctl is-active` for a configurable list of services. Cached for `CIPI_SERVER_STATUS_CACHE_TTL` seconds (default 15).
  - `GET /api/server/metrics?range=1h|6h|24h|7d|30d` — minute-resolution time series captured by the new scheduled command.
  - `cipi:record-server-metrics --prune` — Artisan command, registered with the Laravel scheduler at `everyMinute()->withoutOverlapping()` automatically (controlled by `CIPI_METRICS_ENABLED` and `CIPI_METRICS_RETENTION_DAYS`).
  - Migration `cipi_server_metrics` storing CPU/memory/swap/disk samples plus a JSON snapshot of disks and services.
  - `ServerStatusService` reads `/proc/loadavg`, `/proc/meminfo`, `/proc/cpuinfo`, `/proc/stat`, `df -PBM`, and `systemctl is-active` (no sudo needed for these); falls back to PHP `disk_total_space()` if `df` is unavailable.
- **Device registration for push notifications**
  - Migration `cipi_devices` (per-token unique on `push_token`) plus `Device` model with notification preferences map.
  - `GET /api/devices`, `POST /api/devices`, `PATCH /api/devices/{id}`, `DELETE /api/devices/{id}` — devices are scoped to the API token that registered them; rotating the token invalidates its devices automatically (cascade is enforced at the application layer).
  - `JobStateChanged` event dispatched by `RunCipiCommand` on `started/completed/failed`, plus `SendJobNotifications` listener fan-outing pushes to the registering token's devices.
  - Pluggable push driver via `CipiApi\Notifications\PushDriverContract`. Default driver (`log`) writes to the Laravel log; bind your own implementation (FCM/APNs) in the container under `PushDriverContract::class` and set `CIPI_PUSH_DRIVER` accordingly.
  - Notification preferences honor exact event types (`deploy.success`) and prefixes (`deploy`). Empty preferences mean "everything on".
- **Deploy history & live log tail**
  - `GET /api/apps/{name}/deploys` — cursor-paginated list of `app-deploy*` jobs for the app (status, exit_code, duration, timing, triggered_by). Cursors are opaque base64.
  - `GET /api/apps/{name}/deploys/{job}` — single deploy detail, with parsed `result` when finished.
  - `GET /api/apps/{name}/deploys/{job}/log` — final captured CLI output.
  - `GET /api/jobs/{id}/log/tail?from_byte=N&max_bytes=M` — long-poll log tail. The app sends `from_byte`, the server returns `{chunk, next_byte, log_size, eof}`. Set `eof:true` once the job finished and the file has been fully read.
  - `RunCipiCommand` was rewritten on top of `proc_open` (with non-blocking `stream_select`) so stdout/stderr stream to a per-job log file at `storage/app/cipi-job-logs/{uuid}.log` while the command runs, instead of being captured only at the end.
  - New `JobLogService` with `tail()`, `purgeOlderThan()`, and `cipi:prune-job-logs` Artisan command (scheduled daily at 03:30, `CIPI_JOB_LOGS_RETENTION_DAYS` default 14).
  - `cipi_jobs` migration adds `app`, `log_path`, `started_at`, `finished_at`, `duration_seconds`, `triggered_by`, `token_id` columns. The `app` column is populated automatically from `params.app`/`params.user`. New `forApp()` and `ofTypes()` Eloquent scopes.
- **SSL inspection & expiration radar**
  - `GET /api/apps/{name}/ssl` — TLS handshake against `CIPI_SSL_HOST:CIPI_SSL_PORT` (default `127.0.0.1:443`) with SNI for the app's domain (and aliases). Returns issuer, subject, validity window, days remaining, SAN list, self-signed flag, and a clear `error` field when the handshake fails.
  - `POST /api/apps/{name}/ssl/renew` — explicit renew verb (alias of `POST /apps/{name}/ssl`) for clients that prefer a separate route.
  - `GET /api/server/ssl/expiring?days=14` — returns every domain whose certificate has `days_remaining <= days`, sorted ascending. Implementation iterates `apps.json` and reuses the cached SSL inspector. Useful for the "renewal due" widget and for triggering scheduled push notifications.
  - `SslInspectorService` — caches results for `CIPI_SSL_CACHE_TTL` seconds (default 300).
- **Activity & search**
  - `GET /api/activity?limit=50&type=...&app=...&status=...&cursor=...` — unified timeline derived from `cipi_jobs`, mapping each row to an event-style payload (`type: deploy.success`, `subject.type: app`, `metadata`, etc.).
  - `GET /api/search?q=...` — single-shot search across apps, aliases, and database names. Database listing is best-effort: the endpoint still returns app/alias matches when `cipi db list` is unavailable.
- **OpenAPI** — `info.version` bumped to **1.7.0**. New tags (`Server`, `Devices`, `Activity`, `Search`, `Health`); paths and schemas added for every new endpoint listed above. `JobStatusResponse` now includes `app`, `started_at`, `finished_at`, `duration_seconds`.
- **Token abilities** — three new abilities required by the new endpoints: `server-view`, `ssl-view`, `deploy-view`, `activity-view`. Existing abilities are unchanged.
- **Rate limits** — per-route throttles on the new surface (status `120/min`, metrics `60/min`, ssl/expiring `30/min`, log-tail `120/min`, devices register `30/min`, search `60/min`, ping `60/min`).

### Changed

- **Configuration** — `config/cipi.php` introduces `cipi_binary`, `version_file`, `job_logs.*`, `server.*` (services + cache TTL), `metrics.*`, `ssl.*`, `db_backups.path`, and `push.*`. All keys are env-overridable (`CIPI_*`).
- **`CipiCliService`** — adds `runStreaming(command, $logFile)` so the queued job can tee output to a file as it runs while keeping the same `commandIsPermitted()` whitelist. The `cipi` binary path is now resolved from `cipi.cipi_binary` (default `/usr/local/bin/cipi`).
- **`CipiJobService::dispatch()`** — auto-populates `app`, `triggered_by`, and `token_id` (when called inside an authenticated request) on the `cipi_jobs` row.

### Notes for the main Cipi package

These changes work end-to-end with the current Cipi server; nothing more is *required* on the Cipi side. The follow-up items below would unlock the last bits of telemetry that this package can't safely synthesize on its own — see the README "Mobile companion app" section for the full request list.

## [1.6.10] - 2026-04-27

### Fixed

- **Auth redirect for MCP:** `Authenticate::redirectUsing` in `CipiApiServiceProvider` now includes `/mcp` (and `mcp/*`) alongside `api/*`, so unauthenticated MCP traffic always uses the JSON **401** path instead of a browser redirect. This matches token-only auth for MCP and avoids edge cases where a non-JSON `Accept` header could still hit the web redirect branch (the same class of issue as the missing `login` route fixed in **1.6.2**).

## [1.6.9] - 2026-04-03

### Changed

- **Welcome page (`/`):** Restyled to match Laravel’s default framework error-page layout (inline Tailwind-style utilities, two-column header: **Cipi** | **Easy Laravel Deployments**, **API Swagger** link below). Supports `prefers-color-scheme: dark` alongside the light theme.
- **OpenAPI** `info.version` **1.6.9**.

## [1.6.8] - 2026-04-03

### Changed

- **Welcome page (`/`):** Minimal landing with the title **Cipi - Easy Laravel Deployments** and a single link to **API Swagger** (`/docs`); removed marketing copy, theme toggle, and external links.
- **OpenAPI** `info.version` **1.6.8**.

## [1.6.7] - 2026-04-03

### Added

- **`CipiDatabaseListCliService`:** runs **`sudo cipi db list`** via `CipiCliService`, parses output with `CipiOutputParser` for `GET /api/dbs` and MCP `DbList`.

### Changed

- **`GET /api/dbs`:** Lists databases through the **Cipi server CLI** (synchronous), not a background job and not a direct MySQL connection from Laravel—secrets stay inside Cipi.

### Removed

- **`CipiMysqlDatabaseListService`**, **`CipiServerSecretsService`**, and related **`config/cipi.php`** keys (`mysql_list`, vault paths, `cipi_mysql_list` connection registration) introduced in intermediate iterations.

## [1.6.6] - 2026-04-03

### Fixed

- **`GET /api/dbs` credentials:** The list endpoint no longer reuses the app `mysql` connection by default. A dedicated connection **`cipi_mysql_list`** is registered from `config/cipi.php` → **`mysql_list`**, with **`CIPI_MYSQL_LIST_PASSWORD`** (and optional host/user/socket) so MariaDB can be reached when `.env` has `root` and an empty `DB_PASSWORD` (which caused `Access denied … using password: NO` over `127.0.0.1`).

### Changed

- **`CIPI_MYSQL_LIST_CONNECTION`** default is now **`cipi_mysql_list`** (was `mysql`). Set `CIPI_MYSQL_LIST_CONNECTION=mysql` only if that connection already has a valid password and `SHOW DATABASES` rights.
- **OpenAPI** `info.version` **1.6.6**.

## [1.6.5] - 2026-04-03

### Fixed

- **`GET /api/dbs` / `cipi-cli db list`:** Listing no longer uses the `db list` background job or `sudo cipi`, which could fail (exit code, sudo, CLI environment) even when MySQL was healthy. The endpoint now reads database names and approximate sizes from MySQL/MariaDB using the Laravel DB connection (`CIPI_MYSQL_LIST_CONNECTION`, default `mysql`), matching how `GET /api/apps` reads `apps.json`.

### Added

- **`CipiMysqlDatabaseListService`** and config keys `mysql_list_connection`, `mysql_system_databases` in `config/cipi.php`.
- **`MysqlDatabaseListingUnavailableException`:** returned as JSON **503** when the configured connection is not mysql/mariadb or `SHOW DATABASES` fails.

### Changed

- **MCP `DbList`:** Returns the list inline (no job polling).
- **`CipiOutputParser::parseDbList`:** Completed jobs with an empty list now return `databases: []` instead of `null`.
- **OpenAPI:** `GET /api/dbs` documents **200** + `DbListResponse`, **503**, and `info.version` **1.6.5**.

## [1.6.4] - 2026-04-03

### Fixed

- **Database CLI jobs:** `CipiCliService` now allows all `db` command prefixes (`db list`, `db create`, `db delete`, `db backup`, `db restore`, `db password`). They were missing from the sudo whitelist, so queued jobs failed immediately with “Command not allowed” and clients (including `cipi-cli db list`) saw `job failed` even when Cipi on the server was working.

### Added

- **`DisallowedCipiCommandException`:** thrown when application code tries to queue a Cipi CLI command whose prefix is not in the whitelist, so misconfiguration surfaces at dispatch time instead of as a perpetually failing job.
- **`CipiJobService::dispatch()`** validates the command string with `CipiCliService::commandIsPermitted()` before creating the job record and dispatching `RunCipiCommand`.

### Changed

- **`CipiCliService`:** `ALLOWED_COMMANDS` is a documented `public const`; deploy matching uses the prefix `deploy ` (with a trailing space) so it does not collide with other commands starting with `deploy`.
- **`CipiApiServiceProvider`:** registers a renderable handler so `DisallowedCipiCommandException` returns JSON `500` with an `error` message for `api/*` and JSON requests.
- **OpenAPI / Swagger:** `public/api-docs/openapi.json` `info.version` set to **1.6.4** (aligned with this release).

## [1.6.3] - 2026-04-03

### Changed

- **OpenAPI / Swagger:** `public/api-docs/openapi.json` (served at `/docs`) now documents the database REST API (`/dbs`, backup, restore, password), request bodies, path parameters, and job `type` / `JobResultDb*` shapes for `GET /api/jobs/{id}`. Spec `info.version` set to **1.6.3**.
- **README:** Swagger section notes what the OpenAPI spec covers (including databases and job `result` types).

## [1.6.2] - 2026-04-03

### Fixed

- **Auth redirect without web login:** `CipiApiServiceProvider` registers `Authenticate::redirectUsing` so installs without a named `login` route no longer throw `Route [login] not defined` when Sanctum rejects a request. Unauthenticated `api/*` and JSON requests get a normal 401 flow; browser requests fall back to `/` (welcome) when no `login` route exists.

## [1.6.1] - 2026-03-20

### Changed

- **Custom apps without Git (SFTP-only):** `POST /api/apps` and MCP `AppCreate` align with [Cipi 4.4.4+](https://github.com/cipi-sh/cipi): for `custom: true`, `repository` is optional. Omit `repository` and `branch` to provision a custom app for SFTP upload to `~/htdocs` with no Git deploy. Laravel (non-custom) apps still require `repository`.
- OpenAPI: `AppCreateRequest` documents `custom` and `docroot`; `repository` is no longer globally required in the schema (runtime validation enforces it for Laravel apps).

## [1.6.0] - 2026-03-19

### Added

- Custom app support: `POST /api/apps` now accepts `custom` (boolean) and `docroot` (string) parameters to create non-Laravel apps with classic deploy (no zero-downtime)
- Database management APIs:
  - `GET /api/dbs` — list all databases (`dbs-view`)
  - `POST /api/dbs` — create a database (`dbs-create`)
  - `DELETE /api/dbs/{name}` — delete a database (`dbs-delete`)
  - `POST /api/dbs/{name}/backup` — backup a database (`dbs-manage`)
  - `POST /api/dbs/{name}/restore` — restore from backup (`dbs-manage`)
  - `POST /api/dbs/{name}/password` — regenerate password (`dbs-manage`)
- MCP tools: `DbList`, `DbCreate`, `DbDelete`, `DbBackup`, `DbRestore`, `DbPassword`
- Output parsers for all database CLI commands
- New token abilities: `dbs-view`, `dbs-create`, `dbs-delete`, `dbs-manage`

## [1.5.2] - 2026-03-16

### Changed

- Package renamed to `cipi/api` (from `andreapollastri/cipi-api`)
- README: updated installation command and MCP tools table formatting

## [1.5.1] - 2026-03-16

### Added

- Laravel 13 support

## [1.0.0] - 2026-03-06

### Added

- REST API for Cipi server control panel
  - Apps: list, show, create, edit, delete
  - Aliases: list, create, delete
  - Deploy: deploy, rollback, unlock
  - SSL: install certificate
  - Jobs: show async job status with structured `result` field
- MCP server endpoint at `/mcp` (Streamable HTTP, optional, requires `laravel/mcp` package)
  - Tools: `AppList`, `AppShow`, `AppCreate`, `AppEdit`, `AppDelete`, `AppDeploy`, `AppDeployRollback`, `AppDeployUnlock`, `AliasList`, `AliasAdd`, `AliasRemove`, `SslInstall`
  - Setup instructions for VS Code, Cursor, and Claude Code
- Swagger documentation at `/docs` with job result schemas
- Laravel Sanctum authentication with token abilities (`apps-view`, `apps-create`, `apps-edit`, `apps-delete`, `aliases-view`, `aliases-create`, `aliases-delete`, `deploy-manage`, `ssl-manage`, `mcp-access`)
- Artisan commands: `cipi:token-create`, `cipi:token-list`, `cipi:token-revoke`, `cipi:seed-api-user`
- Queue-based job execution for long-running Cipi operations
- Migration for `personal_access_tokens` table (Sanctum)
- Structured job result parsing: `GET /api/jobs/{id}` returns parsed `result` (app credentials, domain, SSH, database, deploy key, webhook, deploy status, rollback status, unlock status, etc.) based on Cipi CLI output
- MCP routes load only when `laravel/mcp` is installed

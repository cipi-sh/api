# Cipi API

Laravel package that exposes a REST API, an MCP server, and Swagger documentation for the [Cipi](https://cipi.sh) server control panel.

## Requirements

- PHP 8.3+
- Laravel 12.41+ or 13
- `laravel/mcp` ^1.0 for the MCP endpoint (installed by `cipi api`)

## Installation

```bash
composer require cipi/api
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

- **REST API** — CRUD for apps, aliases, www redirects, app/path redirects, prefix proxies, Node apps, databases, SSL, and async jobs (`/api/*`), secured with Laravel Sanctum and token abilities. App create supports optional Git for **custom** apps (SFTP-only), matching Cipi 4.4.4+. Apps can also be taken offline and restored with **suspend / unsuspend** (HTTP 503 maintenance page), matching Cipi 4.5.8+. **HTTP Basic Auth** can be enabled, disabled, and inspected per app via `/api/apps/{name}/basicauth/*` (synchronous, wraps `cipi basicauth`). **App `.env`** (`GET|PUT /api/apps/{name}/env`, ability `apps-env`) merges key/value pairs without replacing the whole file. **Shared `auth.json`** (`/api/apps/{name}/auth`, ability `apps-auth`) is Composer/structured JSON — not HTTP Basic Auth. **Artisan** (`POST /api/apps/{name}/artisan`, ability `apps-artisan`) runs as an async job; poll `GET /api/jobs/{id}` for output. **Whitelisted app run** (`POST /api/apps/{name}/run`, `GET /api/run-commands`, ability `apps-run`) executes non-interactive binaries as the app user (composer, npm, ls, rm, git, … — no nano/vim/less/bash/tinker). **Deploy config** (`GET|PUT /api/apps/{name}/deploy-config`, ability `apps-deploy-config`) edits structured Deployer options (keep_releases, hooks, node_build, extra_artisan) and regenerates `deploy.php` — not a raw PHP upload. Env/auth/artisan/run/deploy-config need **Cipi ≥ 5.0.3**. **WWW / apex redirects** (`/api/apps/{name}/www/*`, ability `www-manage`) and **`POST …/ssl/force`** match [Cipi 4.8+](https://cipi.sh/docs/). **Multi-engine databases** (MariaDB + optional PostgreSQL) via `engine` on `/api/dbs*` and `GET /api/dbs/engines`. **App logs** (`GET /api/apps/{name}/logs`) return paginated nginx, PHP-FPM, and Laravel snapshots (requires `apps-view`). **Server status** (`GET /api/status`) returns the same data as `cipi status` as structured JSON (requires `status-view`). **App/path redirects** (`/api/apps/{name}/redirect*`, abilities `redirects-view`/`redirects-manage`) and **prefix proxies** (`/api/apps/{name}/proxies`, abilities `proxies-view`/`proxies-manage`) wrap `cipi redirect` / `cipi proxy` synchronously (nginx test + revert) — Cipi ≥ 5.3.1 with **API sudoers ≥ 5.4.1**. **Node apps** (Cipi 5.4.0+): app create/edit accept `node` (spa|static|ssr), `framework`, `node_version`, `build`, `start`, `output`, `health_path`; `GET /api/node` lists runtimes, `GET /api/apps/{name}/node` shows app Node status, and `POST /api/apps/{name}/node/restart` does a blue/green restart (abilities `node-view`/`node-manage`). **Deploy audit** (`GET /api/apps/{name}/deploy/audit`, ability `deploy-manage`, Cipi 5.4.0+) returns the hash-chained ledger records. **Deploy status** (`GET /api/apps/{name}/deploy/status`, ability `deploy-manage`, Cipi 5.8.4+) says whether a deploy is running right now — whoever started it, a Git push included — with its trigger and elapsed time, and how the last one ended; `?tail=N` adds the last lines of the deploy log (503 on an older core: the flag is never sent there). **Meilisearch** (`GET /api/search`, `POST /api/apps/{name}/search/enable|disable`, abilities `search-view`/`search-manage`, Cipi 5.2.2+). Read-only host insights: **`GET /api/packages`** (`packages-view`), **`GET /api/monitor`** (`monitor-view`), **`GET /api/zt`** (`zt-view`). **Disk usage** (Cipi 5.5.2+ for the API sudoers; ability `disk-view`): **`GET /api/disk`** returns the server filesystem and every app with files, database, total, share of the disk and soft limit, largest first; **`GET /api/disk/dbs`** every database per installed engine in MB — the figures of `cipi disk --json` / `cipi disk db --json`, measured on request. **`POST /api/apps/{name}/fix-permissions`** (ability `apps-edit`, Cipi 5.2.1+) restores the app home permission model. **App type**: app list/show return `type` (`laravel` | `custom` | `node`) and `custom` — use `type` to tell custom and Node apps apart (Cipi stores `custom: true` on both). **Limits** (`GET|PUT /api/apps/{name}/limits`, abilities `apps-view`/`apps-edit`): PHP-FPM max children, `memory_limit`, Octane workers, queue worker processes, and the **soft disk limit** `disk_limit_gb` (files + database, alerts only — Cipi 5.5.0+). **DNS-01 / wildcard certificates** (Cipi 5.5.0+): `POST /api/apps/{name}/ssl` accepts `dns`, `account`, `wildcard`, `http`; Cloudflare accounts are managed with `GET|PUT /api/ssl/dns` and `DELETE /api/ssl/dns/{account}` (ability `ssl-manage`, tokens never returned). Wildcard primary domains (`*.example.com`) are accepted since Cipi 5.1.1. **App create (Cipi 5.6.0+)** accepts `node_build`, the Laravel asset build run inside every deploy, and the `app-create` / `app-edit` job results report `repository_access` — whether the deploy key can read the repository (`ok`, `nobranch`, `denied`, with git's error and the CLI fix). **cipi.yml** (Cipi 5.6.4+; abilities `yml-view`/`yml-manage`): **`GET /api/apps/{name}/yml`** returns the plan of the app's cipi.yml (file, auto-apply, validity, changes, blockers), **`POST /api/apps/{name}/yml/apply`** applies it as an async job (`app-yml-apply`), and **`POST /api/apps/{name}/yml/auto/enable|disable`** turn on or off the apply after every successful deploy. **Environments** (Cipi 5.8.0+): `POST /api/apps` and `PUT /api/apps/{name}` accept `env`, a label such as `production` (default) or `staging` (lowercase letters, digits, `-`, `_`, max 32 chars); app list/show return it, and `GET /api/apps/{name}/yml` reports the `env` the plan was read for — Cipi picks `cipi-<env>.yml` before `cipi.yml`.
- **IP whitelist** — optional client IP allowlist for `/api/*` and `/mcp` (`/etc/cipi/api-ip-whitelist`, default `*` = allow all). Manage with `cipi api ip-whitelist` or `GET|PUT|POST|DELETE /api/ip-whitelist` (abilities `ip-whitelist-view` / `ip-whitelist-manage`). Requires **Cipi ≥ 5.0.8**.
- **MCP Server** — Model Context Protocol endpoint at `/mcp` for AI-powered integrations.
- **Swagger Docs** — Interactive API reference at `/docs`, generated from `public/api-docs/openapi.json`. The spec covers apps (including env, auth.json, artisan), aliases, www, deploy, SSL, databases (`GET /api/dbs` / `/dbs/engines` via CLI; other `/api/dbs/*` actions use jobs), and job polling (including structured `result` types per job).
- **Since 1.39.0 (Cipi 5.9.0)** — deploy history and every app's last deploy, Node runtimes (install, upgrade, default, remove), backup profiles and runs, databases linked to an app, and database dumps uploaded in chunks. See *Configuration* below. **1.39.1:** `GET /api/status` reports the installed API release as `system.api`; `GET /api/backups` always carries `configured` (an answer without it is a `503`, never an empty `200`) and says where it comes from with `host` and `config_file`. **1.39.2:** PHP 8.6 is accepted by `POST /api/php/install` and as an app's `php`, on Cipi 5.9.1+ (`422` with the version it needs on an older core).
- **Artisan Commands** — `cipi:token-create`, `cipi:token-list`, `cipi:token-revoke`.

## MCP Integration

The MCP server is exposed at `/mcp` using [Streamable HTTP](https://modelcontextprotocol.io/) transport and is secured with the same Sanctum token used by the REST API. A token with the **`mcp-access`** ability is sufficient for **all** MCP tools — per-endpoint REST abilities (`apps-view`, `deploy-manage`, etc.) are not required on `/mcp`.

Generate a token if you haven't already:

```bash
php artisan cipi:token-create
```

Replace `https://your-server.com` and `YOUR_TOKEN` in the examples below with your actual Cipi host and token.

### VS Code

Create (or edit) `.vscode/mcp.json` in your workspace:

```json
{
  "inputs": [
    {
      "type": "promptString",
      "id": "cipi-token",
      "description": "Cipi API Token",
      "password": true
    }
  ],
  "servers": {
    "cipi-api": {
      "type": "http",
      "url": "https://your-server.com/mcp",
      "headers": {
        "Authorization": "Bearer ${input:cipi-token}"
      }
    }
  }
}
```

> Restart VS Code after adding the configuration. The token will be requested on first connection and securely stored.

### Cursor

Create (or edit) `.cursor/mcp.json` in your project root:

```json
{
  "mcpServers": {
    "cipi-api": {
      "url": "https://your-server.com/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

> Restart Cursor after adding the configuration (Cursor v0.40+).

### Claude Code

Run the following command from your terminal:

```bash
claude mcp add --transport http cipi-api https://your-server.com/mcp \
  --header "Authorization: Bearer YOUR_TOKEN"
```

Verify the server is connected:

```bash
claude mcp list
```

### Available MCP Tools

Once connected, the following tools are available to the AI agent:

| Tool                | Description                                           |
| ------------------- | ----------------------------------------------------- |
| `AppList`           | List all apps with type (laravel/custom/node), env label, domains, PHP versions, and aliases |
| `AppShow`           | Show details of a specific app (type, env, domain, PHP, branch, aliases, …) |
| `AppArtisan`        | Run Artisan on a Laravel app (sync; REST uses async jobs) |
| `AppRun`            | Whitelisted non-interactive cmd as app user (async job; Cipi ≥ 5.0.3) |
| `AppRunCommands`    | List allowed `AppRun` binaries                            |
| `AppDeployConfigShow` | Show structured deploy.php options                      |
| `AppDeployConfigUpdate` | Update deploy options (regenerates template)          |
| `AppEnvShow`        | List Laravel app `.env` keys (Cipi ≥ 5.0.3; secrets redacted) |
| `AppEnvUpdate`      | Merge/unset `.env` keys                               |
| `AppAuthJsonShow`   | Show shared `auth.json` (not HTTP Basic Auth)         |
| `AppAuthJsonCreate` | Create shared `auth.json`                             |
| `AppAuthJsonUpdate` | Replace shared `auth.json`                            |
| `AppAuthJsonDelete` | Delete shared `auth.json`                             |
| `AppCreate`         | Create a new app (`custom` for non-Laravel apps; optional Git for custom SFTP-only sites, Cipi 4.4.4+; optional `env` label, Cipi 5.8.0+) |
| `AppEdit`           | Edit an existing app (PHP, branch, repository, domain, `env`, Node fields) |
| `AppDelete`         | Delete an app                                         |
| `AppDeploy`         | Deploy an app                                         |
| `AppDeployRollback` | Rollback the last deploy                              |
| `AppDeployUnlock`   | Unlock a stuck deploy                                 |
| `AppSuspend`        | Take an app offline (HTTP 503) without deleting it    |
| `AppUnsuspend`      | Bring a suspended app back online                     |
| `AppFixPermissions` | Restore the app home permission model (async job; Cipi ≥ 5.2.1) |
| `AppLimitsShow`     | App resource limits + soft disk limit (Cipi ≥ 5.5.0 for disk) |
| `AppLimitsUpdate`   | Update FPM/Octane/worker limits and the soft disk limit (async job) |
| `AppJitShow`        | PHP JIT of an app: runtime, the PHP that answers, on/off, mode, buffer (Cipi ≥ 5.10.0) |
| `AppJitUpdate`      | Turn JIT on or off for an app — checked before, rolled back if the app does not come up (async job) |
| `FrankenPhpStatus`  | FrankenPHP build of each Octane app, the PHP inside it, patched or not (Cipi ≥ 5.10.0) |
| `FrankenPhpUpgrade` | Newest build on the app's own PHP minor, one app or all (async job) |
| `AppBasicAuthStatus`| Show HTTP Basic Auth status (enabled + usernames)     |
| `AppBasicAuthEnable`| Enable Nginx HTTP Basic Auth on an app                |
| `AppBasicAuthDisable` | Disable HTTP Basic Auth on an app                 |
| `AppYmlShow`        | cipi.yml plan: file, env, auto-apply, validity, changes, blockers (read-only, Cipi 5.6.4+) |
| `AppYmlApply`       | Apply the app's cipi.yml now (async job `app-yml-apply`) |
| `AppYmlAuto`        | Turn on/off the cipi.yml apply after every successful deploy |
| `AliasList`         | List aliases for an app                               |
| `AliasAdd`          | Add an alias to an app                                |
| `AliasRemove`       | Remove an alias from an app                           |
| `WwwStatus`         | Show www/apex redirect status (Cipi 4.8+)             |
| `WwwAdd`            | Add www/apex counterpart alias                        |
| `WwwForceToRoot`    | 301 redirect www → apex                               |
| `WwwForceFromRoot`  | 301 redirect apex → www                               |
| `WwwClear`          | Clear www canonical redirect                          |
| `RedirectList`      | List whole-app + path redirects (Cipi ≥ 5.4.1)        |
| `RedirectSet`       | Redirect every hostname of an app to a URL            |
| `RedirectToggle`    | Enable/disable the saved whole-app redirect           |
| `RedirectUnset`     | Remove the whole-app redirect (forget target)         |
| `RedirectAdd`       | Add/update a path redirect (prefix or exact)          |
| `RedirectRemove`    | Remove a path redirect                                |
| `ProxyList`         | List prefix reverse proxies                           |
| `ProxyAdd`          | Add/update a prefix proxy (loopback guard enforced)   |
| `ProxyRemove`       | Remove a prefix proxy                                 |
| `NodeRuntimes`      | List installed Node runtimes + server default (Cipi 5.4.0+) |
| `NodeStatus`        | Node status of an app (mode, framework, build/start)  |
| `NodeRestart`       | Blue/green restart of an SSR Node app (async job)     |
| `DeployAudit`       | Deploy audit ledger records for an app (Cipi 5.4.0+)  |
| `DeployStatus`      | Is a deploy running now, whoever started it (Cipi 5.8.4+) |
| `SearchStatus`      | Meilisearch status + search-enabled apps (Cipi 5.2.2+) |
| `SearchEnable`      | Enable Meilisearch/Scout for a Laravel app            |
| `SearchDisable`     | Disable search (restores previous SCOUT_DRIVER)       |
| `PackageList`       | Optional host packages catalog (read-only)            |
| `MonitorList`       | System monitor checks + state (read-only, Cipi 5.3.0+) |
| `MetricsOverview`   | Server and apps now: CPU, memory, requests, 5xx, healthcheck; last-hour trend, uptime 24h/7d/30d, recent events (Cipi ≥ 5.11.0) |
| `MetricsSeries`     | A metric over time for the host or an app, ~240 points of [ts, avg, max], up to the rollup retention |
| `MetricsUptime`     | Healthcheck uptime, minutes down, incidents, uptime per day — one app or all |
| `MetricsEvents`     | Timeline: monitor state changes, health incidents, deploys, heartbeat |
| `MetricsSettingsShow` | Collector, database size, retention, probe, heartbeat (by host), OpenMetrics (no token) |
| `MetricsSettingsUpdate` | Retention, probe, collector on/off, heartbeat URL — synchronous |
| `ZtStatus`          | Cloudflare Zero Trust status (read-only, Cipi 5.3.0+) |
| `DiskUsage`         | Server filesystem + every app's files/database/total, share of the disk and soft limit (read-only, Cipi 5.5.2+) |
| `DiskDatabases`     | Every database per engine in MB: MariaDB, PostgreSQL, Valkey, Meilisearch (read-only, Cipi 5.5.2+) |
| `DbEngines`         | List installed DB engines and default (Cipi 4.8+)     |
| `DbList`            | List databases (optional `engine` filter)             |
| `DbCreate`          | Create a database (`engine` = mariadb\|pgsql)         |
| `DbBackup`          | Create a compressed backup of a database              |
| `DbRestore`         | Restore a database from a backup file                 |
| `DbPassword`        | Regenerate database password                          |
| `SslInstall`        | Install an SSL certificate (HTTP-01, or Cloudflare DNS-01 with `account` / `wildcard`) |
| `SslDnsList`        | Cloudflare DNS-01 accounts + certificates on each (Cipi ≥ 5.5.0) |
| `SslForce`          | Re-apply HTTP → HTTPS redirect (no new issuance)      |
| `JobShow`           | Poll async job status, result, and CLI output         |
| `AppLogs`           | Read recent app logs (`cipi app logs` types)          |
| `ApiLogShow`        | Read Cipi API host Laravel logs                       |
| `ServerStatus`      | Server snapshot (same JSON as `GET /api/status`)       |
| `ServiceList`       | System service status (`cipi service list`)           |

## Configuration

This package is automatically installed and configured by `cipi api`. No manual setup is needed.

The `CIPI_APPS_JSON` env variable defaults to `/etc/cipi/apps.json`.

Token abilities for `cipi api token create` are defined in `config/cipi.php` (`token_abilities`) and exposed via `php artisan cipi:token-abilities`. The Cipi CLI checklist calls that command when the API package is installed.

**Full access:** `php artisan cipi:token-create --name=<slug> --full-access` (or `--abilities=*`; from the Cipi CLI, `cipi api token create --full-access`, Cipi ≥ 5.9.0) creates a token with Sanctum's wildcard ability `*` instead of a list: it passes every `ability:` check — REST, jobs and `/mcp` — and keeps doing so for the abilities later versions add, which a token holding today's whole list does not get. `cipi:token-list` shows it as `* (full access)`. It is the token for an integration that is meant to do everything (the panel, your own automation); a CI pipeline that only deploys is still better served by `deploy-manage` alone.

**Jobs:** `GET /api/jobs/{id}` needs the ability the job was created under — the one of the endpoint that queued it: `app-create` → `apps-create`, `db-password` → `dbs-manage`, `app-deploy` → `deploy-manage`, `app-artisan` → `apps-artisan`, … (`CipiJobAbilities::ABILITIES`, since 1.36.1; before, any token of the API could read any job by id, passwords included). A token without it gets `404 Job not found`, the same as an unknown id. MCP `JobShow` needs `mcp-access` like every MCP tool.

`GET /api/dbs` runs **`sudo cipi db list`** on the host (synchronously), like the Cipi server CLI: vault and MariaDB access stay inside Cipi, not duplicated in PHP.

**Why other API actions worked but `db` failed:** Cipi configures **`/etc/sudoers.d/cipi-api`** so `www-data` may run **`NOPASSWD`** only for an explicit list of `cipi` subcommands (`app`, `deploy`, `alias`, `ssl`, …). Database commands were missing from that whitelist until **Cipi 4.4.17**, so `sudo` tried to ask for a password and failed without a TTY (`sudo: a terminal is required`). Update the server with **`cipi self-update`** (applies migration 4.4.17) or add the `cipi db …` lines to `cipi-api` sudoers manually (see Cipi `setup.sh`).

**`AppArtisan` / env / auth.json:** require **Cipi CLI ≥ 5.0.3** (`cipi self-update`) so `/etc/sudoers.d/cipi-api` allows `app artisan`, `app env`, and `auth create|edit|show|delete`, and so non-interactive env/auth flags exist. REST Artisan is async (`POST /api/apps/{name}/artisan` → poll job); MCP `AppArtisan` stays synchronous.

**`AppRun` / `AppDeployConfig*`:** require **Cipi CLI ≥ 5.0.3**. App run is non-interactive only. Deploy config is structured knobs (never raw `deploy.php` upload).

**`ServerStatus` / `ServiceList`:** `ServerStatus` returns structured JSON (same as `GET /api/status`), preferring `sudo cipi status` with a host-read fallback. `ServiceList` runs `sudo cipi service list` on the host. Both require `mcp-access` only on `/mcp`. Ensure `cipi-api` sudoers allows `status` and `service list` (see Cipi `setup.sh`).

**Redirects / Proxies / Node:** require **Cipi CLI ≥ 5.4.1** (`cipi self-update`, migration 5.4.1) so `/etc/sudoers.d/cipi-api` allows `redirect *`, `proxy *`, and `node list|status|restart`. The commands themselves exist since Cipi 5.3.1 (redirect/proxy) and 5.4.0 (node). All redirect/proxy writes are synchronous: the CLI validates the rule (loops, collisions, reserved paths, charset), regenerates the vhost, runs `nginx -t`, and reverts on failure. The proxy loopback guard is never bypassed from the API (no `--force`).

**Search / Packages / Monitor / Zero Trust:** the API exposes only what the panel sudoers allow — `search status|list|enable|disable`, `package list`, `monitor list`, `zt status`. Installing or upgrading Meilisearch, installing packages, changing monitor thresholds, and every `zt` mutation stay with the operator on the host CLI, by design.

**Disk usage:** `GET /api/disk` and `GET /api/disk/dbs` run `sudo cipi disk --json` / `sudo cipi disk db --json` **synchronously** and return the CLI figures as they are (GB per app, MB per database). Sizes are measured when asked — `du` over every app home plus one query per database engine — so a server with large apps takes a few seconds: clients should allow more than the usual 30 s. Requires **Cipi ≥ 5.5.2** (`cipi self-update`, migration 5.5.2) so `/etc/sudoers.d/cipi-api` allows `disk` and `disk *`; on an older server sudo asks for a password without a TTY and both endpoints answer 503. Read-only: the soft limit is set with `PUT /api/apps/{name}/limits`.

**cipi.yml:** `GET /api/apps/{name}/yml` runs `sudo cipi yml plan <app> --json` **synchronously**: the cipi.yml of the live release (`file`, null when it has none), `auto`, `valid` with `errors`/`warnings`, and what applying would do — `changes` (`kind`, `summary`), `blockers` (apply refuses while any is listed), `notes`, `in_sync`. `POST /api/apps/{name}/yml/apply` runs `cipi yml apply <app> --yes` as an async job (`app-yml-apply`); its result has `outcome` (`applied`, `unchanged`, `partial`, `blocked`, `invalid`, `no-file`), `applied`, `failed`, `failures`, `blockers`, `errors` — the last four outcomes end the job as `failed` and still carry it. `POST /api/apps/{name}/yml/auto/enable|disable` run `cipi yml auto <app> on|off` synchronously and return `{app, auto, sudoers, file}`. With auto-apply on, every successful deploy applies the file — `cipi deploy`, panel deploys and the Git webhook — so **anyone who can commit to the repository controls the app's aliases, PHP settings, limits, workers, deploy recipe, crons, databases and backups**: confirm before turning it on. Requires **Cipi ≥ 5.6.4** (`cipi self-update`, migration 5.6.4) for `--json` and so `/etc/sudoers.d/cipi-api` allows `yml plan|apply|auto *`; on an older server the endpoints answer 503. `--file` is never passed and is refused for www-data by the CLI. Since **Cipi 5.8.0** the file is chosen per environment — `cipi-<env>.yml` (or `.yaml`) before `cipi.yml` — and the plan and auto responses carry `env`; the cipi.yml schema also gains `deploy.branch` (the branch the app deploys, applied like `cipi app edit --branch`), which the API shows in the plan's `changes` like any other key.

**Environments:** every app carries an `env` label (`production` unless set): `POST /api/apps` takes an optional `env`, passed as `cipi app create --env=<label>` only when sent; `PUT /api/apps/{name}` changes it with `cipi app edit --env=<label>`; `GET /api/apps` and `GET /api/apps/{name}` return it, `production` when the apps.json projection has no key (Cipi < 5.8.0, or an app created before). The label is validated before the job is queued with the CLI's own rule — lowercase letters, digits, `-` and `_`, 32 characters at most — and refused with 422 otherwise. Requires **Cipi ≥ 5.8.0** for `--env`; no sudoers change (`app create *` / `app edit *` are already allowed). MCP: `AppCreate` / `AppEdit` take `env`, `AppList` / `AppShow` / `AppYmlShow` / `AppYmlAuto` return it.

**JIT per app:** `GET /api/apps/{name}/jit` runs `sudo cipi app jit <app> --json` synchronously: `runtime` (`fpm`, `octane`), `php` (for an Octane app, the PHP inside its FrankenPHP build), `enabled`, `mode`, `buffer`, `supported`, `note`. `PUT /api/apps/{name}/jit` with `enabled` (and, when true, optional `mode` `tracing`|`function`, `buffer` 16M–1G) runs `cipi app jit <app> on|off` as an async job (`app-jit`, ability `apps-edit`). The CLI first checks that the app's PHP starts with JIT (Xdebug or pcov turn it off), then restarts the PHP-FPM of the app's PHP version — every app on it is interrupted for a moment — or the app's Octane, and puts the previous setting back when the app does not come up. On PHP-FPM the JIT buffer is shared by every app on that PHP version with JIT; the largest one asked for is used. Requires **Cipi ≥ 5.10.0** (the routes answer 503/422 on an older core, before anything is queued).

**FrankenPHP builds:** `GET /api/frankenphp` (ability `php-view`) runs `sudo cipi frankenphp status --json`: for each Octane app the pinned build, the PHP inside it and its `state` (`ok`, `vulnerable`, `mismatch` — not the app's PHP minor —, `unpinned`, `missing`), `restart_pending`, plus the builds on the server and `autoupdate`. `POST /api/frankenphp/upgrade` (ability `php-manage`, optional `app`) runs `cipi frankenphp upgrade <app>|--all` as an async job (`frankenphp-upgrade`): the newest build on each app's own PHP minor and FrankenPHP major, Octane restarted, checked, and put back on the previous build when it does not answer; a release on another PHP minor is reported in the job result (`available_on_other_php`), not applied. Choosing a given build (`cipi frankenphp use`) and importing one stay on the server's shell. Requires **Cipi ≥ 5.10.0**.

**Metrics & health history:** the core (Cipi ≥ 5.11.0) collects every minute into SQLite on the server — host CPU, load, memory, swap, disk, network; per app CPU, memory, PHP-FPM children and `max_children` hits, requests and 5xx per minute, queue workers, and the healthcheck's status and time — and keeps raw minutes 7 days, 15-minute rollups 30 days and events 90 days by default. The API reads it through the CLI, synchronously (ability `metrics-view`): **`GET /api/metrics`** (`cipi metrics overview --json`: everything a dashboard needs in one call), **`GET /api/metrics/series`** and **`GET /api/apps/{name}/metrics`** (`?metrics=cpu,mem_mb&range=24h` or `from`/`to`; ~240 points of `[ts, avg, max]`, `source` `raw` or `rollup`), **`GET /api/metrics/uptime`** (`?app=&range=30d`), **`GET /api/metrics/events`** (`?app=&source=monitor|health|deploy|heartbeat&range=7d&limit=`), **`GET /api/metrics/settings`**. With `metrics-manage`: **`PUT /api/metrics/settings`** (`enabled`, `probe`, `raw_days` 1–90, `rollup_days` ≤ 400 and ≥ raw, `resolution_minutes` 5/10/15/30/60, `events_days` 7–730), **`PUT|DELETE /api/metrics/heartbeat`** (`url`, `every` 1–60 minutes) and **`POST /api/metrics/heartbeat/test`**. Nothing secret comes back: the heartbeat URL is reported by host, the OpenMetrics token as `token_set`. The OpenMetrics endpoint (a port and firewall rules), `prune` and `reset` stay on the host. On an older core every route answers `503` with `needs Cipi 5.11.0+`.

**cipi/agent behind basic auth:** `GET /api/apps/{name}/basicauth/agent` → `{mode, public, prefix}`; `PUT` with `mode` `public` (default) or `protected` (ability `apps-basicauth`, Cipi ≥ 5.11.0). While basic auth is on and the live release has cipi/agent, its routes (`/cipi/` or `CIPI_ROUTE_PREFIX`) are left out of it — they check their own tokens — unless `protected`.

**Limits / soft disk limit:** `PUT /api/apps/{name}/limits` runs `sudo cipi app limits <app> …` as an async job (`app-limits`): FPM/Octane/worker changes rebuild the pool or Supervisor programs, and `disk_limit_gb` (Cipi 5.5.0+) measures the app and reports usage in the job result. `disk_limit_gb: null` removes the limit. The disk limit is read from apps.json when the projection carries `disk_limit_gb`, otherwise from `cipi app limits <app>`.

**DNS-01 / Cloudflare accounts:** `PUT /api/ssl/dns` runs `sudo cipi ssl dns set` **synchronously**, so the token is never written to the queue or the jobs table, and is never returned. As with the SMTP password, the token reaches the CLI as an argument: sudo may log the command line in the host auth log. `DELETE /api/ssl/dns/{account}` is refused while a certificate still renews with the account. Needs Cipi CLI ≥ 5.5.0 (`--http` / `--no-wildcard` on `ssl install` need 5.5.1).

**Deploy audit:** `GET /api/apps/{name}/deploy/audit` and the `DeployAudit` MCP tool read the root-only hash-chained ledger via `sudo cipi deploy <app> --audit --json` (Cipi 5.4.0+). An empty list means the ledger has not been written yet (first deploy after `cipi self-update`).

**Deploy status:** `GET /api/apps/{name}/deploy/status` and the `DeployStatus` MCP tool run `sudo cipi deploy <app> --status --json [--tail=N]` (Cipi 5.8.4+): `state` is `running`, `queued` (a webhook push waiting for the per-minute cron), `interrupted` (a run that never ended, nothing alive — the lock may be stuck) or `idle`; `current` carries the run in flight (trigger `webhook` / `cli` / `panel` / `rollback`, branch, `started_at`, `elapsed`), `last` how the previous run ended. The call is refused with `503` on a core older than 5.8.4 (`CipiVersionService`, reading `/etc/cipi/version`): there `--status` is an unknown flag and `cipi deploy <app>` would start a deploy. The panel (cipi/gui 4.1.0+) polls it to show deploys it did not start.

**Deploy history / last deploys:** `GET /api/apps/{name}/deploy/history?limit=30` runs `sudo cipi deploy --history <app> --json` and `GET /api/deploys/last` runs `sudo cipi deploy --last --json` (Cipi 5.9.0+, abilities `apps-view` or `deploy-manage`, synchronous). A run has the keys of `last` in the deploy status — `result`, `kind` (`deploy` / `rollback`), `release`, `duration` (seconds), `exit`, `trigger`, `branch`, `from_release`, `started_at`, `finished_at` — and comes from the deploy log *and its rotated files*: `deploy.log` is rotated every night, so a client that read it through `GET /api/apps/{name}/logs?type=deploy` had the duration of a deploy until the first rotation only. `/api/deploys/last` maps each app to its last run, `null` when it was never deployed. Both are sent with the flag first, a form an older core answers with "app not found" instead of deploying, and both answer `503` below Cipi 5.9.0.

**Node runtimes:** `POST /api/node/install` (`major`), `POST /api/node/upgrade` (optional `major`, optional `restart` — SSR apps move to the new binary blue/green), `PUT /api/node/default` (`major` or `system`) and `DELETE /api/node/{major}` are async jobs (`node-install`, `node-upgrade`, `node-default`, `node-remove`) under the ability `node-runtimes-manage`. They need the panel sudoers of **Cipi 5.9.0** (migration 5.9.0) and answer `503` on an older core. The CLI refuses to remove a major an app uses or that is the server default: the job fails with its message.

**Backups:** `GET /api/backups` (`backups-view`) runs `sudo cipi backup status --json`: destinations without their keys, every profile with what its globs cover right now (`covers.apps`, `covers.databases`), its last run, `overdue`, `running`, and `uncovered` — the apps and databases no enabled profile takes. It writes nothing and sends no mail, so it can be polled. `GET /api/backups/runs[?profile=]` lists the runs each destination holds (it lists the buckets: allow a few seconds). Under `backups-manage`: `POST /api/backups/run` (optional `profile`) starts `cipi backup run --detach` and returns `202` at once — the run goes on by itself and shows as `running`, then in `last`; `POST /api/backups/verify` is an async job (`backup-verify`); `POST /api/backups/profiles`, `PUT|DELETE /api/backups/profiles/{name}`, `POST …/{name}/enable|disable` and `POST /api/backups/destinations/test` are synchronous, and a request the CLI refuses is a `422` with its message. Profile fields: `scope` (`all`, `files`, `db`), `apps`, `databases`, `exclude_databases`, `exclude_tables` (lists of names or globs), `every` (`30m`, `6h`, `1d`) or `cron`, `destinations` (`local`, `s3`, named ones), `keep`, `keep_days`, `keep_weeks`, `encrypt`. **Destinations are read-only**: `cipi backup configure` and `cipi backup destination add` take an S3 secret key and stay on the server, as do `fetch`, `key`, `prune` and `profile remove --purge`. All of it needs **Cipi 5.9.0** (`503` below).

**Databases and apps:** on Cipi 5.9.0+ `GET /api/dbs` asks the list as JSON and each database carries `app` and `link` — `linked` (an explicit link), `own` (created with a Laravel app) or `namespace` (`<app>_…`) — plus `size_mb`; on an older core the table is parsed as before and the two keys are absent. `POST /api/dbs` takes an optional `app` (`cipi db create --app=`), `POST /api/dbs/{name}/link` (`app`, optional `engine`) and `DELETE /api/dbs/{name}/link` run `cipi db link|unlink` synchronously (`dbs-manage`). A link is a label in the app's entry: `GET /api/apps` and `GET /api/apps/{name}` return it as `databases`.

**PHP versions:** `php` on app create, app edit and `POST /api/php/install` takes 8.3, 8.4, 8.5 or 8.6 (`php_versions` in `config/cipi.php`; 8.6 needs Cipi 5.9.1+). A server installed before Cipi 4.5.4 can still have 7.4–8.2: `GET /api/php` lists them in `versions` with the apps on each and leaves them out of `installable`, `GET /api/status` reports their FPM, and an app on one keeps it until `PUT /api/apps/{name}` moves it to a current version. They cannot be installed or assigned (`422`); removing one stays on the host (`cipi php remove`).

**Dump upload:** `POST /api/dbs/uploads` (`filename`, `size`) → `POST /api/dbs/uploads/{id}/chunks` once per chunk, in order (multipart `index` and `chunk`) → `POST /api/dbs/{name}/restore` with `upload_id` instead of `file` (`dbs-manage`, Cipi 5.9.0+). The dump travels in pieces so that no request has to fit it into the limits of nginx and PHP; `GET /api/dbs/uploads/limits` gives the sizes. Limits are in `config/cipi.php` (`db_upload`: `max_mb` 2048, `chunk_mb` 8, `spool_mb` 4096; env `CIPI_DB_UPLOAD_*`). Only `.sql` and `.sql.gz` are accepted, and the content must match the name. The pieces are appended to a file in the API's storage (`storage/app/cipi-db-uploads`, mode 0600); Cipi 5.9.0 opens a dump found there as `www-data`, not as root, which is why the routes refuse an older core. The file is deleted when the restore job ends, whatever its result, and anything not restored within a day is swept.

**Tests:** `php tests/standalone.php` checks the upload service, the backup flag builder and the allow-list without the framework (no `vendor/` needed).

## License

MIT

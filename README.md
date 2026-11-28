<p align="center">
  <a href="https://cipi.sh">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://cipi.sh/brand/cipi-logo-dark.svg">
      <img src="https://cipi.sh/brand/cipi-logo.svg" alt="Cipi" height="56">
    </picture>
  </a>
</p>

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

- **REST API** — CRUD for apps, aliases, www redirects, app/path redirects, prefix proxies, Node apps, databases, SSL, and async jobs (`/api/*`), secured with Laravel Sanctum and token abilities. App create supports optional Git for **custom** apps (SFTP-only), matching Cipi 4.4.4+. Apps can also be taken offline and restored with **suspend / unsuspend** (HTTP 503 maintenance page), matching Cipi 4.5.8+. **HTTP Basic Auth** can be enabled, disabled, and inspected per app via `/api/apps/{name}/basicauth/*` (synchronous, wraps `cipi basicauth`). **App `.env`** (`GET|PUT /api/apps/{name}/env`, ability `apps-env`) merges key/value pairs without replacing the whole file, and puts the keys in an `order` (every key once, Cipi 5.13.0). **Shared `auth.json`** (`/api/apps/{name}/auth`, ability `apps-auth`) is Composer/structured JSON — not HTTP Basic Auth. **Artisan** (`POST /api/apps/{name}/artisan`, ability `apps-artisan`) runs as an async job; poll `GET /api/jobs/{id}` for output. **Whitelisted app run** (`POST /api/apps/{name}/run`, `GET /api/run-commands`, ability `apps-run`) executes non-interactive binaries as the app user (composer, npm, ls, rm, git, … — no nano/vim/less/bash/tinker). **Deploy config** (`GET|PUT /api/apps/{name}/deploy-config`, ability `apps-deploy-config`) edits structured Deployer options (keep_releases, hooks, node_build, extra_artisan) and regenerates `deploy.php` — not a raw PHP upload. Env/auth/artisan/run/deploy-config need **Cipi ≥ 5.0.3**. **WWW / apex redirects** (`/api/apps/{name}/www/*`, ability `www-manage`) and **`POST …/ssl/force`** match [Cipi 4.8+](https://cipi.sh/docs/). **Multi-engine databases** (MariaDB + optional PostgreSQL) via `engine` on `/api/dbs*` and `GET /api/dbs/engines`. **App logs** (`GET /api/apps/{name}/logs`) return paginated nginx, PHP-FPM, and Laravel snapshots (requires `apps-view`). **Server status** (`GET /api/status`) returns the same data as `cipi status` as structured JSON (requires `status-view`). **App/path redirects** (`/api/apps/{name}/redirect*`, abilities `redirects-view`/`redirects-manage`) and **prefix proxies** (`/api/apps/{name}/proxies`, abilities `proxies-view`/`proxies-manage`) wrap `cipi redirect` / `cipi proxy` synchronously (nginx test + revert) — Cipi ≥ 5.3.1 with **API sudoers ≥ 5.4.1**. **Node apps** (Cipi 5.4.0+): app create/edit accept `node` (spa|static|ssr), `framework`, `node_version`, `build`, `start`, `output`, `health_path`; `GET /api/node` lists runtimes, `GET /api/apps/{name}/node` shows app Node status, and `POST /api/apps/{name}/node/restart` does a blue/green restart (abilities `node-view`/`node-manage`). **Deploy audit** (`GET /api/apps/{name}/deploy/audit`, ability `deploy-manage`, Cipi 5.4.0+) returns the hash-chained ledger records. **Deploy status** (`GET /api/apps/{name}/deploy/status`, ability `deploy-manage`, Cipi 5.8.4+) says whether a deploy is running right now — whoever started it, a Git push included — with its trigger and elapsed time, and how the last one ended; `?tail=N` adds the last lines of the deploy log (503 on an older core: the flag is never sent there). **Meilisearch** (`GET /api/search`, `POST /api/apps/{name}/search/enable|disable`, abilities `search-view`/`search-manage`, Cipi 5.2.2+). Read-only host insights: **`GET /api/packages`** (`packages-view`; installing and removing from the closed catalog below), **`GET /api/monitor`** (`monitor-view`), **`GET /api/zt`** (`zt-view`; with Cipi 5.16.0+ its `details` hold everything `cipi zt status --json` reports) — and, with `zt-manage`, the mesh network of Zero Trust is joined, left and locked under **`/api/zt/mesh`** (Cipi 5.18.0+, below). **Disk usage** (Cipi 5.5.2+ for the API sudoers; ability `disk-view`): **`GET /api/disk`** returns the server filesystem and every app with files, database, total, share of the disk and soft limit, largest first; **`GET /api/disk/dbs`** every database per installed engine in MB — the figures of `cipi disk --json` / `cipi disk db --json`, measured on request. **`POST /api/apps/{name}/fix-permissions`** (ability `apps-edit`, Cipi 5.2.1+) restores the app home permission model. **App type**: app list/show return `type` (`laravel` | `custom` | `node`) and `custom` — use `type` to tell custom and Node apps apart (Cipi stores `custom: true` on both). **Limits** (`GET|PUT /api/apps/{name}/limits`, abilities `apps-view`/`apps-edit`): PHP-FPM max children, `memory_limit`, Octane workers, queue worker processes, and the **soft disk limit** `disk_limit_gb` (files + database, alerts only — Cipi 5.5.0+). **DNS-01 / wildcard certificates** (Cipi 5.5.0+): `POST /api/apps/{name}/ssl` accepts `dns`, `account`, `wildcard`, `http`; Cloudflare accounts are managed with `GET|PUT /api/ssl/dns` and `DELETE /api/ssl/dns/{account}` (ability `ssl-manage`, tokens never returned). Wildcard primary domains (`*.example.com`) are accepted since Cipi 5.1.1. **App create (Cipi 5.6.0+)** accepts `node_build`, the Laravel asset build run inside every deploy, and the `app-create` / `app-edit` job results report `repository_access` — whether the deploy key can read the repository (`ok`, `nobranch`, `denied`, with git's error and the CLI fix). **cipi.yml** (Cipi 5.6.4+; abilities `yml-view`/`yml-manage`): **`GET /api/apps/{name}/yml`** returns the plan of the app's cipi.yml (file, auto-apply, validity, changes, blockers), **`POST /api/apps/{name}/yml/apply`** applies it as an async job (`app-yml-apply`), and **`POST /api/apps/{name}/yml/auto/enable|disable`** turn on or off the apply after every successful deploy. **Environments** (Cipi 5.8.0+): `POST /api/apps` and `PUT /api/apps/{name}` accept `env`, a label such as `production` (default) or `staging` (lowercase letters, digits, `-`, `_`, max 32 chars); app list/show return it, and `GET /api/apps/{name}/yml` reports the `env` the plan was read for — Cipi picks `cipi-<env>.yml` before `cipi.yml`.
- **Optional packages** (Cipi 5.14.0+; ability `packages-manage`) — `POST /api/packages/{package}` installs and `DELETE /api/packages/{package}` removes one entry of the server's closed catalog (`browsershot`, `fonts-cjk`, `avif`, `ffmpeg`, `imagemagick`, `image-optimizers`, `poppler-utils`, `pdftk`, `libreoffice`, `pandoc`, `tesseract`, and the `sec-*` scanners [Hackly](https://github.com/andreapollastri/hackly) runs — some of them upstream releases pinned in Cipi by version and SHA-256) as async jobs. See *Configuration* below.
- **Nginx directives** (Cipi 5.14.0+; abilities `nginx-view`, `nginx-manage`) — `GET|PUT|DELETE /api/apps/{name}/nginx` read, replace and remove the directives an app adds to its own server block. Tested with `nginx -t` before the reload; a refused text is a `422` with the reasons and changes nothing. From the API a subset of nginx's language only — no `access_log` path, `include`, `listen`, `server_name`, `*_pass`.
- **Live job output** — `GET /api/jobs/{id}` on a running job carries what its command has printed so far.
- **Page cache** (Cipi 5.17.0+; abilities `nginx-view`, `nginx-manage`) — `GET /api/apps/{name}/cache` reads an app's nginx page cache, `PUT` turns it on or off (`enabled`, and with it `ttl`, `size`, `clear_on_deploy`, `exclude`), `PUT /api/apps/{name}/cache/exclude` sets the paths of its own an app never caches (`{"exclude": ["/cart*", "/feed"]}`; `*` is anything), `POST /api/apps/{name}/cache/clear` empties it; `GET /api/cache` lists every app. nginx keeps the page PHP made and answers the next visitors without running PHP (`fastcgi_cache` on PHP-FPM, `proxy_cache` on Octane) — not OPcache, which is always on and keeps compiled PHP in memory. Synchronous; `nginx -t` runs before the reload. Cipi empties the cache after every deploy and rollback; `clear_command` in the status is what a custom deploy recipe, a cron or the app's own code runs (`sudo /usr/local/bin/cipi-cache-clear <app>`).
- **Page cache — some pages** (Cipi 5.18.0+, `nginx-manage`) — `POST /api/apps/{name}/cache/purge` with `paths`: a path (`/blog/post/`, every domain of the app, with its `?utm` variants), a prefix (`/blog/*`) or a URL on one of the app's domains; at most 500. Answers `purged`. The app's own code does the same with `sudo /usr/local/bin/cipi-cache-clear <app> <page>…` — the WordPress plugin [cipi-sh/cache-wp](https://github.com/cipi-sh/cache-wp) and the PHP package [cipi-sh/cache-php](https://github.com/cipi-sh/cache-php) call it.
- **Backups to SFTP** (Cipi 5.18.0+, `backups-manage`) — `POST /api/backups/destinations` (`name`, `host`, `user`, `port`, `path`) adds a destination reached over SSH and answers with the `public_key` the remote must accept; `PUT`/`DELETE /api/backups/destinations/{name}`, `GET …/{name}/key`. No secret travels: Cipi makes the key on the server.
- **Backups set up from the panel** (Cipi 5.19.0+, `backups-manage`) — `POST /api/backups/destinations` with `type: "s3"` (`name`, `bucket`, `key`, `secret`, `region`, `endpoint`) adds an S3 bucket, `PUT` changes one (the secret is kept when left out), `DELETE` removes it; `PUT /api/backups/config` sets up where backups go — `type: "local"` (this server only), `"s3"` (the default bucket) or `"sftp"` (Cipi 5.19.1+: `host`, `user`, `port`, `path`, `name`: the answer's `destination.public_key` is the key to install on the remote); `GET /api/backups/key` shows the encryption key once (logged and notified on the server), `POST /api/backups/key/rotate` rotates it; `POST /api/backups/prune` (`profile`, `dry_run`) applies the retention now (job `backup-prune`). Every bucket is reached with its keys before it is saved. **A secret key never rides a command line**: the API writes it into the CLI's standard input (`--secret-stdin`) in a synchronous call — never a job, never stored, never answered.
- **Update policy** (Cipi 5.18.0+, `updates-view` / `updates-manage`) — `GET|PUT /api/self-update/policy`: `verify` (install a release only when its tag `v<version>` is signed by a trusted key), `delay_hours` (wait before installing a new commit; 0 to 168), `fingerprint` (the first trust of the shipped keys). Both off by default.
- **Octane PHP** (Cipi 5.17.0+; abilities `php-view`, `php-manage`) — `GET /api/apps/{name}/octane` says which PHP answers the requests (the one inside the FrankenPHP build) and which runs Composer, artisan, cron and workers; `PUT /api/apps/{name}/octane/php` (`php`: `8.5`) puts both on one version by its number (job `octane-php`), `POST /api/apps/{name}/octane/restart` restarts Octane and checks it (job `octane-restart`).
- **Valkey per app** (Cipi 5.17.0+; abilities `services-view`, `services-manage`) — `GET /api/valkey` lists who is on the server's shared instance and who has its own; `POST /api/valkey/isolate` (`app`, or `all: true`) gives an app its own instance and `POST /api/valkey/share` moves it back (jobs `valkey-isolate`, `valkey-share`); `POST /api/valkey/{name}/restart` restarts an instance; `POST /api/valkey/flush-shared` empties the shared one and changes its password once nobody uses it. No password is ever in an answer.
- **Notification settings** (Cipi 5.17.0+; abilities `notifications-view`, `notifications-manage`) — `GET|PUT /api/notifications/settings`: the time zone of the `Time:` line (`timezone`, `null` for the server's own) and the name notifications give the server (`server_name`, `null` for its hostname).
- **Firewall** (Cipi 5.14.0+; abilities `firewall-view` / `firewall-manage`) — `GET /api/firewall` reads UFW, fail2ban and CrowdSec in one call; open a port (`POST /api/firewall/rules`), remove what allow made (`DELETE /api/firewall/rules`), block or unblock an address (`POST|DELETE /api/firewall/blocks`), the fail2ban threshold (`PUT /api/firewall/attempts`), unban (`DELETE /api/firewall/bans`), CrowdSec's allowlist (`POST|DELETE /api/firewall/crowdsec/allowlist`) and CrowdSec itself (`POST /api/firewall/crowdsec/enable|disable`, async jobs); from Cipi 5.15.0 the visitor's address behind Cloudflare (`PUT|DELETE /api/firewall/real-ip`, `POST /api/firewall/real-ip/refresh`). See *Configuration* below.
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
| `OctanePhpShow`     | The PHP of an Octane app: what answers requests, what runs the rest, the fix for a mismatch (Cipi ≥ 5.17.0) |
| `OctanePhpSwitch`   | Put an Octane app on a PHP version by its number (async job) |
| `PageCacheShow`     | The nginx page cache of an app, or of every app (Cipi ≥ 5.17.0) |
| `PageCacheUpdate`   | Turn the page cache on or off; ttl, size, clear after deploy, paths never cached |
| `PageCacheClear`    | Empty an app's page cache                             |
| `ValkeyList`        | Valkey per app: own, shared, external — read-only (Cipi ≥ 5.17.0) |
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
| `PackageList`       | Optional host packages catalog with `kind`, `binaries`, `missing`, `source`, `version`, `outdated` (read-only; install/remove via REST or the host CLI) |
| `MonitorList`       | System monitor checks + state (read-only, Cipi 5.3.0+) |
| `MetricsOverview`   | Server and apps now: CPU, memory, requests, 5xx, healthcheck; last-hour trend, uptime 24h/7d/30d, recent events (Cipi ≥ 5.11.0) |
| `MetricsSeries`     | A metric over time for the host or an app, ~240 points of [ts, avg, max], up to the rollup retention |
| `MetricsUptime`     | Healthcheck uptime, minutes down, incidents, uptime per day — one app or all |
| `MetricsEvents`     | Timeline: monitor state changes, health incidents, deploys, heartbeat |
| `MetricsSettingsShow` | Collector, database size, retention, probe, heartbeat (by host), OpenMetrics (no token) |
| `MetricsSettingsUpdate` | Retention, probe, collector on/off, heartbeat URL — synchronous |
| `NotificationsShow` | Every notification channel (no secrets) and how many triggers are on; with `triggers`, each trigger (Cipi ≥ 5.12.0) |
| `NotificationChannelSave` | Add a channel (SMTP, Resend, SES, Slack, Discord, Mattermost, Google Chat, Telegram, ntfy, Pushover, webhook) or change one; secrets left out are kept |
| `NotificationChannelTest` | Send a test to one channel or `all`; says when the channel refused it |
| `NotificationChannelRemove` | Remove a channel |
| `NotificationTriggersUpdate` | Turn triggers on/off (`on`, `off`, `all`, `reset`) |
| `ZtStatus`          | Zero Trust status: Cloudflare Tunnel (Cipi 5.3.0+) and the mesh network the server is on — Tailscale or NetBird (Cipi 5.18.0+). Read-only: the mesh is changed with the REST API, not from MCP |
| `FirewallStatus`    | UFW rules, ports kept open, Zero Trust locks, fail2ban threshold and bans, CrowdSec state, allowlist and decisions (read-only, Cipi 5.14.0+) |
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

**Moving apps to another server** (`cipi sync`, Cipi 5.18.0+, ability **`sync-manage`**): `GET /api/sync/key` gives this server's sync key; `POST /api/sync/trust` (`{key}`) on the *target* lets the server it belongs to push there, and `DELETE /api/sync/trust` takes it back; `POST /api/sync/check` (`{host, port?, user?}`) says whether a target accepts the key and runs Cipi — `ok`, the target's version, or a `reason` (`not-trusted`, `unreachable`, `host-key-changed`, `no-cipi`); `POST /api/sync/push` (`{host, port?, apps?, with_db?, with_storage?}`) queues the transfer — export, send over SSH, import on the target, where apps that exist are updated and the others created — as a `sync-push` job of up to two hours. **The archive is encrypted with a passphrase the source makes for that one transfer and hands to the target's import over SSH: no route takes, returns or queues one.** `sync-manage` is as strong as `ssh-manage`: whoever holds it can have the server send every app — code, `.env`, databases — to a host of their choosing, and can let another server's `cipi` user sign in here. A token made with a list of abilities has it only once it is named there.

**A database with a custom or a Node app:** `POST /api/apps` takes `db` — the name of a new database to create with the app and link to it (Cipi 5.18.0+, `cipi app create --db=NAME`), on `engine` or the server's default; its credentials come in the job result (`database`). A Laravel app is created with its own and refuses the field (`422`); an older core answers `503`.

**Jobs:** `GET /api/jobs/{id}` needs the ability the job was created under — the one of the endpoint that queued it: `app-create` → `apps-create`, `db-password` → `dbs-manage`, `app-deploy` → `deploy-manage`, `app-artisan` → `apps-artisan`, … (`CipiJobAbilities::ABILITIES`, since 1.36.1; before, any token of the API could read any job by id, passwords included). A token without it gets `404 Job not found`, the same as an unknown id. MCP `JobShow` needs `mcp-access` like every MCP tool. **How long a job may run:** a deploy gets two hours — its frontend build alone has 30 minutes with Cipi 5.18.0, and up to an hour where the app asks for it (`build_timeout` in the deploy config; Deployer's own limit was 300 seconds) — and every other job ten minutes (`CipiJobTimeout`; the limit travels with the job, over the worker's `--timeout`). A job the worker stops ends as `failed`, `exit_code` 124, with what the command had printed and a line that says so: it no longer stays `running`.

`GET /api/dbs` runs **`sudo cipi db list`** on the host (synchronously), like the Cipi server CLI: vault and MariaDB access stay inside Cipi, not duplicated in PHP.

**Why other API actions worked but `db` failed:** Cipi configures **`/etc/sudoers.d/cipi-api`** so `www-data` may run **`NOPASSWD`** only for an explicit list of `cipi` subcommands (`app`, `deploy`, `alias`, `ssl`, …). Database commands were missing from that whitelist until **Cipi 4.4.17**, so `sudo` tried to ask for a password and failed without a TTY (`sudo: a terminal is required`). Update the server with **`cipi self-update`** (applies migration 4.4.17) or add the `cipi db …` lines to `cipi-api` sudoers manually (see Cipi `setup.sh`).

**`AppArtisan` / env / auth.json:** require **Cipi CLI ≥ 5.0.3** (`cipi self-update`) so `/etc/sudoers.d/cipi-api` allows `app artisan`, `app env`, and `auth create|edit|show|delete`, and so non-interactive env/auth flags exist. REST Artisan is async (`POST /api/apps/{name}/artisan` → poll job); MCP `AppArtisan` stays synchronous.

**`AppRun` / `AppDeployConfig*`:** require **Cipi CLI ≥ 5.0.3**. App run is non-interactive only. Deploy config is structured knobs (never raw `deploy.php` upload).

**`ServerStatus` / `ServiceList`:** `ServerStatus` returns structured JSON (same as `GET /api/status`), preferring `sudo cipi status` with a host-read fallback. `ServiceList` runs `sudo cipi service list` on the host. Both require `mcp-access` only on `/mcp`. Ensure `cipi-api` sudoers allows `status` and `service list` (see Cipi `setup.sh`).

**Redirects / Proxies / Node:** require **Cipi CLI ≥ 5.4.1** (`cipi self-update`, migration 5.4.1) so `/etc/sudoers.d/cipi-api` allows `redirect *`, `proxy *`, and `node list|status|restart`. The commands themselves exist since Cipi 5.3.1 (redirect/proxy) and 5.4.0 (node). All redirect/proxy writes are synchronous: the CLI validates the rule (loops, collisions, reserved paths, charset), regenerates the vhost, runs `nginx -t`, and reverts on failure. The proxy loopback guard is never bypassed from the API (no `--force`).

**Search / Monitor / Zero Trust:** the API exposes only what the panel sudoers allow — `search status|list|enable|disable`, `monitor list`, `zt status`, and the mesh commands of `zt tailscale|netbird` listed below. Installing or upgrading Meilisearch, changing monitor thresholds, and every change to **Cloudflare's** side of Zero Trust (`zt enable`, `hostname`, `access`, `lock`…) stay with the operator on the host CLI, by design. `GET /api/zt` covers both sides: Cloudflare Tunnel (`enabled`, `tunnel`, the locks, and everything else in `details`) and, from Cipi 5.18.0, the mesh network — `mesh` (`tailscale`, `netbird` or `null`), `mesh_state`, `mesh_ip`, and `details.mesh` with the server's address and name on the network, peers, the node key's expiry, its own SSH/HTTP locks, the last SSH login seen over the mesh and warnings.

**Zero Trust — the mesh network** (Tailscale or NetBird; Cipi ≥ 5.18.0, ability `zt-manage`; `503` on an older core before anything runs). Synchronous, each answering `{data: <Zero Trust after the change, as GET /api/zt>, message: <what the CLI said>}`; a refusal is a `422` with the CLI's words in `error`.

| Route | Does | Body |
|---|---|---|
| `POST /api/zt/mesh` | Join a network (`cipi zt <provider> enable`): installs the client, signs in, lets the mesh interface through UFW. Closes nothing | `provider` `tailscale`\|`netbird`; `key` (auth key / setup key — left out to adopt a client already connected); `hostname`; `control_url` (Headscale, self-hosted NetBird); `direct`, `accept_dns`, `accept_routes`; Tailscale only: `tags`, `ssh`, `reauth` |
| `DELETE /api/zt/mesh` | Leave it (`disable --yes`): reopens what its locks closed, removes the client | — |
| `POST /api/zt/mesh/lock` | Close a port to the world, open on the mesh only (`lock ssh\|http --yes`) | `port` `ssh`\|`http`; `client` (optional, see below) |
| `DELETE /api/zt/mesh/lock` | Reopen it (`unlock ssh\|http`) | `port` `ssh`\|`http` |
| `PUT /api/zt/mesh/ssh` | Tailscale SSH on or off (`ssh enable\|disable`) | `enabled` |

What keeps a click from locking everyone out — enforced by the CLI when sudo comes from the API, which never sends `--force` (and the CLI ignores it from there):

- **`lock ssh`** wants the mesh connected, UFW active, sshd listening on the mesh address, a node key that does not expire, and **a login sshd accepted over the mesh** (`details.mesh.ssh.seen`): sign in once with `ssh cipi@<mesh address>`, then close the port. With Tailscale SSH on it is done at the shell.
- **`lock http`** is refused while an app renews its certificate over HTTP-01 (`details.mesh.http01_apps`), and when the caller reaches the API on the public address the lock would close. The address of the request goes to the CLI (`--via`), which finds the route it came by: `mesh`, `local` (the panel on the same server), `tunnel` (the API behind the Cloudflare tunnel) pass; `public` and `unknown` do not. A panel on the same server reaches the API locally, but its own pages are on 80/443: it sends `client` — the address of the person's browser — in the body of every change (the status in the answer is then read for them too) and as `GET /api/zt?client=`, and the lock is refused when they look at the panel on the public address (over the mesh, or with the panel's hostname on the tunnel, it goes ahead).
- **`DELETE /api/zt/mesh`** is refused when the caller comes over the mesh itself.
- `GET /api/zt` says all of it beforehand: `details.mesh.panel` = `{via, from, client, blocked: {lock_ssh, lock_http, disable}}`, each `null` or the reason — a client can grey the button out and show why.

The join key is validated (8–512 characters, no spaces or quotes), passed to the CLI as a flag and never stored, queued or returned; if the CLI repeats it in an error it is replaced by `[key]`. `zt-manage` is as strong as `ssh-manage` — whoever holds it can put the server on a network of their own — so a token made with a list of abilities has it only once it is named there (`cipi api token create --abilities=…,zt-view,zt-manage`); a `--full-access` token has it, as it has every ability.

**Optional packages:** **`GET /api/packages`** (`packages-view`) runs `sudo cipi package list --json`: every entry of the server's catalog with `packages` (the apt names, as installed), `description`, `kind` (`tools`, or `libraries` for `browsershot` and `fonts-cjk` — nothing on PATH), `binaries`, `missing`, `installed`, `partial`, and `source` (`ubuntu`, or `upstream` for the releases Cipi pins by version and SHA-256 — `sec-nuclei`, `sec-zap`, `sec-nikto`, `sec-wpscan`, `sec-trivy`, `sec-semgrep`, `sec-checkov`), `version` (the pinned one), `installed_version` and `outdated` (all from Cipi 5.14.0; an older core reads as tools from Ubuntu without them). An `outdated` upstream entry has an older version installed: installing it updates it (`409` only when the pinned version is there), removing it is allowed. With `packages-manage` (Cipi ≥ 5.14.0, whose sudoers allow `package install|remove *`): **`POST /api/packages/{package}`** queues `cipi package install <package> --yes` (`package-install`; result `binaries`, `missing`, `already`, and `version` for an upstream entry — a download whose SHA-256 is not the pinned one fails the job, nothing installed) and **`DELETE /api/packages/{package}`** queues `cipi package remove <package> --yes` (`package-remove`; result `apt` — what was purged — or `still_needed` for libraries other packages use). Only ids of the server's catalog reach a job: anything else is `404`; `409` when already installed (install) or not installed (remove); `503` on an older core. The catalog is closed — the CLI refuses any other name too — and the API never sends `--force` or `--autoremove`, which the CLI ignores from the panel: the 1 GB free-space check holds, and a removal takes the entry and what only it needed, never a package that depends on it (the job fails, nothing changed) nor orphans that were already there. apt takes from seconds to a few minutes; jobs time out at 10.

**Disk usage:** `GET /api/disk` and `GET /api/disk/dbs` run `sudo cipi disk --json` / `sudo cipi disk db --json` **synchronously** and return the CLI figures as they are (GB per app, MB per database). Sizes are measured when asked — `du` over every app home plus one query per database engine — so a server with large apps takes a few seconds: clients should allow more than the usual 30 s. Requires **Cipi ≥ 5.5.2** (`cipi self-update`, migration 5.5.2) so `/etc/sudoers.d/cipi-api` allows `disk` and `disk *`; on an older server sudo asks for a password without a TTY and both endpoints answer 503. Read-only: the soft limit is set with `PUT /api/apps/{name}/limits`.

**cipi.yml:** `GET /api/apps/{name}/yml` runs `sudo cipi yml plan <app> --json` **synchronously**: the cipi.yml of the live release (`file`, null when it has none), `auto`, `valid` with `errors`/`warnings`, and what applying would do — `changes` (`kind`, `summary`), `blockers` (apply refuses while any is listed), `notes`, `in_sync`. `POST /api/apps/{name}/yml/apply` runs `cipi yml apply <app> --yes` as an async job (`app-yml-apply`); its result has `outcome` (`applied`, `unchanged`, `partial`, `blocked`, `invalid`, `no-file`), `applied`, `failed`, `failures`, `blockers`, `errors` — the last four outcomes end the job as `failed` and still carry it. `POST /api/apps/{name}/yml/auto/enable|disable` run `cipi yml auto <app> on|off` synchronously and return `{app, auto, sudoers, file}`. With auto-apply on, every successful deploy applies the file — `cipi deploy`, panel deploys and the Git webhook — so **anyone who can commit to the repository controls the app's aliases, PHP settings, limits, workers, deploy recipe, crons, databases and backups**: confirm before turning it on. Requires **Cipi ≥ 5.6.4** (`cipi self-update`, migration 5.6.4) for `--json` and so `/etc/sudoers.d/cipi-api` allows `yml plan|apply|auto *`; on an older server the endpoints answer 503. `--file` is never passed and is refused for www-data by the CLI. Since **Cipi 5.8.0** the file is chosen per environment — `cipi-<env>.yml` (or `.yaml`) before `cipi.yml` — and the plan and auto responses carry `env`; the cipi.yml schema also gains `deploy.branch` (the branch the app deploys, applied like `cipi app edit --branch`), which the API shows in the plan's `changes` like any other key.

**Environments:** every app carries an `env` label (`production` unless set): `POST /api/apps` takes an optional `env`, passed as `cipi app create --env=<label>` only when sent; `PUT /api/apps/{name}` changes it with `cipi app edit --env=<label>`; `GET /api/apps` and `GET /api/apps/{name}` return it, `production` when the apps.json projection has no key (Cipi < 5.8.0, or an app created before). The label is validated before the job is queued with the CLI's own rule — lowercase letters, digits, `-` and `_`, 32 characters at most — and refused with 422 otherwise. Requires **Cipi ≥ 5.8.0** for `--env`; no sudoers change (`app create *` / `app edit *` are already allowed). MCP: `AppCreate` / `AppEdit` take `env`, `AppList` / `AppShow` / `AppYmlShow` / `AppYmlAuto` return it.

**JIT per app:** `GET /api/apps/{name}/jit` runs `sudo cipi app jit <app> --json` synchronously: `runtime` (`fpm`, `octane`), `php` (for an Octane app, the PHP inside its FrankenPHP build), `enabled`, `mode`, `buffer`, `supported`, `note`. `PUT /api/apps/{name}/jit` with `enabled` (and, when true, optional `mode` `tracing`|`function`, `buffer` 16M–1G) runs `cipi app jit <app> on|off` as an async job (`app-jit`, ability `apps-edit`). The CLI first checks that the app's PHP starts with JIT (Xdebug or pcov turn it off), then restarts the PHP-FPM of the app's PHP version — every app on it is interrupted for a moment — or the app's Octane, and puts the previous setting back when the app does not come up. On PHP-FPM the JIT buffer is shared by every app on that PHP version with JIT; the largest one asked for is used. Requires **Cipi ≥ 5.10.0** (the routes answer 503/422 on an older core, before anything is queued).

**FrankenPHP builds:** `GET /api/frankenphp` (ability `php-view`) runs `sudo cipi frankenphp status --json`: for each Octane app the pinned build, the PHP inside it and its `state` (`ok`, `vulnerable`, `mismatch` — not the app's PHP minor —, `unpinned`, `missing`), `restart_pending`, plus the builds on the server and `autoupdate`. `POST /api/frankenphp/upgrade` (ability `php-manage`, optional `app`) runs `cipi frankenphp upgrade <app>|--all` as an async job (`frankenphp-upgrade`): the newest build on each app's own PHP minor and FrankenPHP major, Octane restarted, checked, and put back on the previous build when it does not answer; a release on another PHP minor is reported in the job result (`available_on_other_php`), not applied. Choosing a given build (`cipi frankenphp use`) and importing one stay on the server's shell. Requires **Cipi ≥ 5.10.0**.

**Metrics & health history:** the core (Cipi ≥ 5.11.0) collects every minute into SQLite on the server — host CPU, load, memory, swap, disk, network; per app CPU, memory, PHP-FPM children and `max_children` hits, requests and 5xx per minute, queue workers, and the healthcheck's status and time — and keeps raw minutes 7 days, 15-minute rollups 30 days and events 90 days by default. The API reads it through the CLI, synchronously (ability `metrics-view`): **`GET /api/metrics`** (`cipi metrics overview --json`: everything a dashboard needs in one call), **`GET /api/metrics/series`** and **`GET /api/apps/{name}/metrics`** (`?metrics=cpu,mem_mb&range=24h` or `from`/`to`; ~240 points of `[ts, avg, max]`, `source` `raw` or `rollup`), **`GET /api/metrics/uptime`** (`?app=&range=30d`), **`GET /api/metrics/events`** (`?app=&source=monitor|health|deploy|heartbeat&range=7d&limit=`), **`GET /api/metrics/settings`**. With `metrics-manage`: **`PUT /api/metrics/settings`** (`enabled`, `probe`, `raw_days` 1–90, `rollup_days` ≤ 400 and ≥ raw, `resolution_minutes` 5/10/15/30/60, `events_days` 7–730), **`PUT|DELETE /api/metrics/heartbeat`** (`url`, `every` 1–60 minutes) and **`POST /api/metrics/heartbeat/test`**. Nothing secret comes back: the heartbeat URL is reported by host, the OpenMetrics token as `token_set`. The OpenMetrics endpoint (a port and firewall rules), `prune` and `reset` stay on the host. On an older core every route answers `503` with `needs Cipi 5.11.0+`.

**Notifications:** every channel and every trigger of `cipi notification` (Cipi ≥ 5.12.0), synchronously. With `notifications-view` (or `smtp-view`): **`GET /api/notifications`** (channels, SMTP first, with state and target; how many triggers are on; the channel types) and **`GET /api/notifications/triggers`** (id, category, label, enabled). With `notifications-manage` (or `smtp-manage`): **`POST /api/notifications/channels`** (`type`, `id`, and the fields of the type — smtp: `host port user password from to tls`; resend: `api_key from to`; ses: `region access_key secret_key from to`; slack, discord, mattermost, googlechat: `url`; telegram: `token chat_id`; ntfy: `url priority`; pushover: `token user`; webhook: `url secret`; `enabled`, `test`), **`PUT /api/notifications/channels/{id}`** (only what changes; a secret left out is kept; `clear_secret` for a webhook), **`DELETE`**, **`POST …/{id}/enable|disable|test`** (`all` tests every enabled channel; `422` with the reason when the channel refused it), **`PUT /api/notifications/triggers`** (`{triggers: {deploy_success: false}}`, `on`, `off`, `all`) and **`POST /api/notifications/triggers/reset`**. Secrets go to the server's vault and never come back (`token_set`, `api_key_set`, …); nothing is queued, so they never reach the jobs table. The `/api/smtp` routes are unchanged. From Cipi 5.15.0 a channel takes `events` on add and update — what it receives: `null` (everything), or categories (`security`, `deploy`, `backup`, …), single triggers (`backup_fail`) and `urgent` (failures and security events) — and `GET /api/notifications` lists the `categories` with the `events` of each channel; an older core is refused (`503`) rather than ignoring the field.

**Firewall:** what `cipi firewall`, `cipi ban` and `cipi crowdsec` do on the server, for the panel's Firewall tab (Cipi ≥ 5.14.0; every route answers `503` on an older core, before anything runs). **`GET /api/firewall`** (`firewall-view`) runs `sudo cipi firewall status --json`: `ufw` (active, default policies, `rules` — each with `action`, `port`, `proto`, `from`, `family` `both`/`v4`/`v6`, `comment`, `kind` `port`/`block`/`other`, `managed_by` the Cipi feature that owns it, `protected`, `removable`), `protected_ports` (the SSH port sshd listens on, 80, 443), `zero_trust` locks, `fail2ban` (`attempts` and its default, find and ban times, every jail with `banned`) and `crowdsec` (engine, bouncer, `allowlist`, `decisions`, `enable_blockers`). With `firewall-manage`, synchronously, each answering `{data: <the firewall after the change>, message}`: **`POST /api/firewall/rules`** (`port` or `8000:8100`, optional `from`, `proto` tcp|udp), **`DELETE /api/firewall/rules`** (the same, `proto` any for a rule made without one, `action` allow|deny), **`POST|DELETE /api/firewall/blocks`** (`address`: every port refused to it, ahead of every allow), **`PUT /api/firewall/attempts`** (`attempts` 1–100, `null` for the default), **`DELETE /api/firewall/bans`** (`ip`: fail2ban and CrowdSec), **`POST|DELETE /api/firewall/crowdsec/allowlist`** (`address`). **`POST /api/firewall/crowdsec/enable`** and **`/disable`** are jobs (`crowdsec-enable`, `crowdsec-disable`): enabling answers `422` with `blockers` at once when the server cannot take it (RAM, a reverse proxy without real_ip), and puts the address of the request on CrowdSec's allowlist. Refused (`422`, the CLI's words): denying or removing the SSH port, 80 or 443 for everyone, reopening what a Zero Trust lock keeps closed, blocking the address of the request or a range wider than /8 (IPv4) or /16 (IPv6). The API never sends `--force`, and the CLI ignores it from the panel. CrowdSec's rescue listener stays on the server. **Cloudflare real IP** (Cipi ≥ 5.15.0): `real_ip` in `GET /api/firewall` (`enabled`, `kept_by` `real-ip`/`zero-trust`/`both`/`other`, `ranges`, `refreshed_at`, `can_disable`, `bans_active`); **`PUT /api/firewall/real-ip`** turns it on (`cipi nginx real-ip cloudflare`, or fetches the ranges again when on), **`POST /api/firewall/real-ip/refresh`** fetches Cloudflare's ranges again, **`DELETE /api/firewall/real-ip`** turns it off (`off --yes`: the client asks first) — `409` when the request itself came through Cloudflare, since from then on the API would see Cloudflare instead of the panel.

**cipi/agent behind basic auth:** `GET /api/apps/{name}/basicauth/agent` → `{mode, public, prefix}`; `PUT` with `mode` `public` (default) or `protected` (ability `apps-basicauth`, Cipi ≥ 5.11.0). While basic auth is on and the live release has cipi/agent, its routes (`/cipi/` or `CIPI_ROUTE_PREFIX`) are left out of it — they check their own tokens — unless `protected`.

**Limits / soft disk limit:** `PUT /api/apps/{name}/limits` runs `sudo cipi app limits <app> …` as an async job (`app-limits`): FPM/Octane/worker changes rebuild the pool or Supervisor programs, and `disk_limit_gb` (Cipi 5.5.0+) measures the app and reports usage in the job result. `disk_limit_gb: null` removes the limit. The disk limit is read from apps.json when the projection carries `disk_limit_gb`, otherwise from `cipi app limits <app>`.

**DNS-01 / Cloudflare accounts:** `PUT /api/ssl/dns` runs `sudo cipi ssl dns set` **synchronously**, so the token is never written to the queue or the jobs table, and is never returned. As with the SMTP password, the token reaches the CLI as an argument: sudo may log the command line in the host auth log. `DELETE /api/ssl/dns/{account}` is refused while a certificate still renews with the account. Needs Cipi CLI ≥ 5.5.0 (`--http` / `--no-wildcard` on `ssl install` need 5.5.1).

**Deploy audit:** `GET /api/apps/{name}/deploy/audit` and the `DeployAudit` MCP tool read the root-only hash-chained ledger via `sudo cipi deploy <app> --audit --json` (Cipi 5.4.0+). An empty list means the ledger has not been written yet (first deploy after `cipi self-update`).

**Deploy status:** `GET /api/apps/{name}/deploy/status` and the `DeployStatus` MCP tool run `sudo cipi deploy <app> --status --json [--tail=N]` (Cipi 5.8.4+): `state` is `running`, `queued` (a webhook push waiting for the per-minute cron), `interrupted` (a run that never ended, nothing alive — the lock may be stuck) or `idle`; `current` carries the run in flight (trigger `webhook` / `cli` / `panel` / `rollback`, branch, `started_at`, `elapsed`), `last` how the previous run ended. The call is refused with `503` on a core older than 5.8.4 (`CipiVersionService`, reading `/etc/cipi/version`): there `--status` is an unknown flag and `cipi deploy <app>` would start a deploy. The panel (cipi/gui 4.1.0+) polls it to show deploys it did not start.

**Deploy history / last deploys:** `GET /api/apps/{name}/deploy/history?limit=30` runs `sudo cipi deploy --history <app> --json` and `GET /api/deploys/last` runs `sudo cipi deploy --last --json` (Cipi 5.9.0+, abilities `apps-view` or `deploy-manage`, synchronous). A run has the keys of `last` in the deploy status — `result`, `kind` (`deploy` / `rollback`), `release`, `duration` (seconds), `exit`, `trigger`, `branch`, `from_release`, `started_at`, `finished_at` — and comes from the deploy log *and its rotated files*: `deploy.log` is rotated every night, so a client that read it through `GET /api/apps/{name}/logs?type=deploy` had the duration of a deploy until the first rotation only. `/api/deploys/last` maps each app to its last run, `null` when it was never deployed. Both are sent with the flag first, a form an older core answers with "app not found" instead of deploying, and both answer `503` below Cipi 5.9.0.

**Node runtimes:** `POST /api/node/install` (`major`), `POST /api/node/upgrade` (optional `major`, optional `restart` — SSR apps move to the new binary blue/green), `PUT /api/node/default` (`major` or `system`) and `DELETE /api/node/{major}` are async jobs (`node-install`, `node-upgrade`, `node-default`, `node-remove`) under the ability `node-runtimes-manage`. They need the panel sudoers of **Cipi 5.9.0** (migration 5.9.0) and answer `503` on an older core. The CLI refuses to remove a major an app uses or that is the server default: the job fails with its message.

**Backups:** `GET /api/backups` (`backups-view`) runs `sudo cipi backup status --json`: destinations without their keys, every profile with what its globs cover right now (`covers.apps`, `covers.databases`), its last run, `overdue`, `running`, and `uncovered` — the apps and databases no enabled profile takes. It writes nothing and sends no mail, so it can be polled. `GET /api/backups/runs[?profile=]` lists the runs each destination holds (it lists the buckets: allow a few seconds). Under `backups-manage`: `POST /api/backups/run` (optional `profile`) starts `cipi backup run --detach` and returns `202` at once — the run goes on by itself and shows as `running`, then in `last`; `POST /api/backups/verify` is an async job (`backup-verify`); `POST /api/backups/profiles`, `PUT|DELETE /api/backups/profiles/{name}`, `POST …/{name}/enable|disable` and `POST /api/backups/destinations/test` are synchronous, and a request the CLI refuses is a `422` with its message. Profile fields: `scope` (`all`, `files`, `db`), `apps`, `databases`, `exclude_databases`, `exclude_tables` (lists of names or globs), `exclude_paths` (folders and files left out of the app archives — tar patterns relative to the app such as `storage/app/uploads` or `*.mp4`, `<apps>:<pattern>` for some apps only; Cipi 5.13.0, refused below it), `every` (`30m`, `6h`, `1d`) or `cron`, `destinations` (`local`, `s3`, named ones), `keep`, `keep_days`, `keep_weeks`, `encrypt`. **Destinations are read-only**: `cipi backup configure` and `cipi backup destination add` take an S3 secret key and stay on the server, as do `fetch`, `key`, `prune` and `profile remove --purge`. All of it needs **Cipi 5.9.0** (`503` below).

**Databases and apps:** on Cipi 5.9.0+ `GET /api/dbs` asks the list as JSON and each database carries `app` and `link` — `linked` (an explicit link), `own` (created with a Laravel app) or `namespace` (`<app>_…`) — plus `size_mb`; on an older core the table is parsed as before and the two keys are absent. `POST /api/dbs` takes an optional `app` (`cipi db create --app=`), `POST /api/dbs/{name}/link` (`app`, optional `engine`) and `DELETE /api/dbs/{name}/link` run `cipi db link|unlink` synchronously (`dbs-manage`). A link is a label in the app's entry: `GET /api/apps` and `GET /api/apps/{name}` return it as `databases`.

**PHP versions:** `php` on app create, app edit and `POST /api/php/install` takes 8.3, 8.4, 8.5 or 8.6 (`php_versions` in `config/cipi.php`; 8.6 needs Cipi 5.9.1+). A server installed before Cipi 4.5.4 can still have 7.4–8.2: `GET /api/php` lists them in `versions` with the apps on each and leaves them out of `installable`, `GET /api/status` reports their FPM, and an app on one keeps it until `PUT /api/apps/{name}` moves it to a current version. They cannot be installed or assigned (`422`); removing one stays on the host (`cipi php remove`).

**Dump upload:** `POST /api/dbs/uploads` (`filename`, `size`) → `POST /api/dbs/uploads/{id}/chunks` once per chunk, in order (multipart `index` and `chunk`) → `POST /api/dbs/{name}/restore` with `upload_id` instead of `file` (`dbs-manage`, Cipi 5.9.0+). The dump travels in pieces so that no request has to fit it into the limits of nginx and PHP; `GET /api/dbs/uploads/limits` gives the sizes. Limits are in `config/cipi.php` (`db_upload`: `max_mb` 2048, `chunk_mb` 8, `spool_mb` 4096; env `CIPI_DB_UPLOAD_*`). Only `.sql` and `.sql.gz` are accepted, and the content must match the name. The pieces are appended to a file in the API's storage (`storage/app/cipi-db-uploads`, mode 0600); Cipi 5.9.0 opens a dump found there as `www-data`, not as root, which is why the routes refuse an older core. The file is deleted when the restore job ends, whatever its result, and anything not restored within a day is swept.

**Tests:** `php tests/standalone.php` checks the upload service, the backup flag builder and the allow-list without the framework (no `vendor/` needed).

## License

MIT

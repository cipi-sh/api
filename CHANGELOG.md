# Changelog

All notable changes to this project will be documented in this file.

## [1.49.0] - 2026-11-28

For Cipi 5.19.0: backups set up and managed from the panel — the default bucket or local copies only, S3 buckets next to the SFTP servers, the encryption key, the retention now.

### Added

- `POST /api/backups/destinations` takes `type: "s3"` (`name`, `bucket`, `key`, `secret`, `region`, `endpoint`); `PUT /api/backups/destinations/{name}` changes one — the secret kept when left out — and tells an S3 bucket from an SFTP server by its fields or, with none, by asking the server; `DELETE` removes either (`cipi backup destination remove` on 5.19.0, `backup sftp remove` before).
- `PUT /api/backups/config` — `cipi backup configure`, where backups go: `type` `local` (this server only; `local_only: true` too), `s3` (the default bucket) or `sftp` (an SFTP server: `host`, `user`, `port`, `path`, `name` — `sftp` by default; Cipi 5.19.1+). The answer is the backup status after it, with `type` and `destination` — for an SFTP server its fields and the `public_key` it has to accept. The local and temp folders are not set from the API.
- `GET /api/backups/key` (the encryption key, once — the server logs and notifies it), `POST /api/backups/key/rotate`, `POST /api/backups/prune` (`profile`, `dry_run`; job `backup-prune`). All under `backups-manage`.
- `CipiCliService::runWithInput()`: a command with something on its standard input. **The secret key of a bucket goes there** (`--secret-stdin`): never on the command line (`ps`, logs), never in a job, never in an answer.
- The fields of a bucket are checked before anything runs — a key or a secret with a space, a quote or a line break is a `422` — and the bucket is reached with its keys before the server saves it.
- OpenAPI 1.49.0. `tests/standalone.php`: 688 checks.

## [1.48.0] - 2026-11-27

For Cipi 5.18.0: the mesh network of Zero Trust — Tailscale or NetBird. `GET /api/zt` reads it, and five routes under `/api/zt/mesh` join it, leave it and lock the server behind it, so a panel can do everything the CLI does. New abilities: `zt-manage`, `sync-manage`, `updates-view`, `updates-manage`.

### Added

- **Backups to SFTP** (Cipi 5.18.0+): `POST /api/backups/destinations` adds an SFTP destination — `name`, `host`, `user`, `port`, `path` — a VPS with a backup user, a Hetzner Storage Box (port 23), a NAS. Cipi makes its key on the server and answers with `public_key`, to install on the remote; `PUT /api/backups/destinations/{name}` changes one (`new_key: true` for a new key), `GET …/{name}/key` gives the key again, `DELETE …/{name}` removes a destination no profile uses. `GET /api/backups` lists SFTP destinations with `type: "sftp"`. An S3 destination is still added at the shell: the allow-list takes `backup sftp add|edit|key|remove`, which touches SFTP destinations only.
- **How the server updates Cipi** (Cipi 5.18.0+, abilities `updates-view`, `updates-manage`): `GET|PUT /api/self-update/policy` — `verify` (a release installed only when its tag is signed by a trusted key), `delay_hours` (0 to 168), and `fingerprint` to trust the shipped keys the first time. Both off by default. Starting an update is not in the API.

- **Page cache — purge some pages** (Cipi 5.18.0+, ability `nginx-manage`): `POST /api/apps/{name}/cache/purge` with `{"paths": ["/blog/hello-world/", "/category/news/*", "https://www.example.com/x/"]}`. A path is that page on every domain and alias of the app, http and https, with its query-string variants; a trailing `*` is a prefix; a URL is that host only. At most 500 pages; answers `purged`, the cached files deleted, and the rest of the cache stays warm. MCP tool `PageCachePurge`.

- **`GET /api/zt`: the mesh** (`cipi zt tailscale|netbird`, Cipi 5.18.0). Zero Trust has two sides: Cloudflare Tunnel publishes the sites, a mesh network is how the people who run the server reach it. The same call answers for both.
  - `mesh` — `tailscale`, `netbird`, or `null` while the server is on none (and on an older Cipi); `mesh_state` — `connected` / `disconnected`; `mesh_ip` — the server's address on the network.
  - `details.mesh` — what `cipi zt status --json` says of it: `provider`, `label`, `connected`, `state` (the client's own word: `Running`, `NeedsLogin`…), `ip`, `ip6`, `name`, `fqdn`, `network`, `control_url` (Headscale, a self-hosted NetBird), `version`, `tags`, `key_expiry` and `key_expires_in_days`, `peers` (`total`, `online`), `ssh` (`provider_ssh`, `ports`, `command`, `seen` — the last login sshd accepted over the mesh), `lock` (`ssh`, `http`), `direct`, `firewall_active`, `http01_apps`, `warnings`.
  - `details.mesh.panel` — the address of the request goes to the CLI (`--via`), which answers what this caller may do from where it is: `via` (`mesh`, `local`, `tunnel`, `public`, `unknown`), `from`, and `blocked` `{lock_ssh, lock_http, disable}`, each `null` or the reason it would be refused. `GET /api/zt?client=<address>` and `client` on the lock are for a panel on the same server: the address of the person at it (`--client`), answered in `details.mesh.panel.client`.
  - `enabled`, `cloudflared`, `tunnel`, `lock_http`, `lock_ssh` and `ssh_hostname` are Cloudflare's, as they were: a server with a mesh and no tunnel answers `enabled: false` and `mesh: "tailscale"`.
- **The mesh from the panel** — ability **`zt-manage`**, synchronous, each answering `{data, message}` with Zero Trust as it is after the change:
  - `POST /api/zt/mesh` — join: `provider` (`tailscale` | `netbird`), `key` (left out to adopt a client that is already connected: the CLI gets `--adopt` and never waits for one), `hostname`, `control_url`, `direct`, `accept_dns`, `accept_routes`, and for Tailscale `tags`, `ssh`, `reauth`. Nothing is closed by joining.
  - `DELETE /api/zt/mesh` — leave: what its locks closed is reopened first.
  - `POST /api/zt/mesh/lock` and `DELETE /api/zt/mesh/lock` — `{port: "ssh" | "http"}`: close the SSH port, or 80/443, to the world and reopen it.
  - `PUT /api/zt/mesh/ssh` — `{enabled}`: Tailscale SSH.
  - A refusal is a `422` with the CLI's words; a Cipi older than 5.18.0 a `503` before anything runs.
  - `CipiZtMeshService`, `ZtMeshController`, `ZtRefusedException`.
- **`GET /api/firewall`: `zero_trust.by`** — who holds each lock: `{http: ["cloudflare"], ssh: ["tailscale"]}`. A port closed by `cipi zt tailscale|netbird lock` is refused to `POST /api/firewall/rules` like one closed by Cloudflare's lock, and the CLI's message names the command that reopens it. Empty lists on an older Cipi, where a lock is Cloudflare's.
- MCP `ZtStatus`: the same fields, described. OpenAPI 1.48.0: a `Zero Trust` tag with `GET /zt` and the five mesh routes; `ZtMeshJoinRequest`, `ZtMeshLockRequest`, `ZtMeshSshRequest`, `ZtMeshChangeResponse`; `mesh`, `mesh_state`, `mesh_ip` and `details.mesh` on `ZtStatusResponse`; `by` on `FirewallStatus.zero_trust`.

- **Moving apps to another server: `/api/sync`** (`cipi sync`, Cipi 5.18.0+) — ability **`sync-manage`**:
  - `GET /api/sync/key` — this server's sync key (`key`, `type`, `fingerprint`, `comment`).
  - `POST /api/sync/trust` and `DELETE /api/sync/trust` (`{key}`) — on the target: let the server the key belongs to push here, and take that back. A key is one line — a type, the key, at most a comment — and its blob must be of that type; anything else is a `422` before the CLI is asked.
  - `POST /api/sync/check` (`{host, port?, user?}`) — does the target accept this server's key, does Cipi answer: `ok`, `cipi`, `auto_passphrase`, or a `reason`. A target that says no is a `200`.
  - `POST /api/sync/push` (`{host, port?, user?, apps?, with_db?, with_storage?}`) — the transfer, as a `sync-push` job (two hours, like a deploy): `cipi sync push … --import --auto-passphrase`.
  - `CipiSyncService`, `SyncController`, `SyncUnsupportedException` (an older core: `503`). OpenAPI: a `Sync` tag, the five operations, `SyncKey`, `SyncKeyRequest`, `SyncTarget`, `SyncCheck`, `SyncPushRequest`.
- **`POST /api/apps`: `db`** — the name of a new database made with a **custom or a Node app** and linked to it (`cipi app create --db=NAME`, Cipi 5.18.0+): a user of the same name, a generated password, on `engine` or the server's default. Its credentials come in the `app-create` job result (`database`), like a Laravel app's. Checked before the job is queued: a name that is not a database name (`422`), a Laravel app — it is created with its own (`422`) — and an older core (`503`). `engine` is now read for a custom or a Node app when `db` is sent. MCP `AppCreate`: the same field.
- **Deploy config: `build_timeout` and `step_timeout`** (`GET|PUT /api/apps/{name}/deploy-config`, Cipi 5.18.0+) — the two clocks of a deploy, in seconds, per app: how long the frontend build may run (60–3600, 1800 unless set) and how long every other command may (60–1800, 300 unless set). Sending the default number puts an app back on it. An older core answers `503` rather than taking the flag for nothing.
- **A Node app has deploy options**: the same route answers for it with `runtime: "node"`, `keep_releases`, `predeploy_snapshot`, `build_timeout`, `step_timeout`, and takes those four. An artisan option sent to it is a `422` that names it. MCP `AppDeployConfigShow` / `AppDeployConfigUpdate`: the same.

### Fixed

- **A deploy started from the panel was stopped after ten minutes, whatever it was doing.** Every job had 600 seconds (`RunCipiCommand::$timeout`), a deploy included — and a deploy is the one command that is long by nature: the clone, Composer, then the frontend build, to which Cipi 5.18.0 gives up to 30 minutes of its own (Deployer stopped it after 300 seconds: `The command "…/node-build.sh …" exceeded the timeout of 300 seconds`). The same deploy, started from the server's shell or by a Git push, had no such ceiling. **A deploy job now has two hours** (the longest build an app can ask for is one); a rollback, an unlock and every other job keep the ten minutes. `CipiJobTimeout::forCommand()` decides, the job carries its limit, and that counts over the worker's `--timeout=600`: nothing changes in the `cipi-queue` unit.
- **A job the worker stopped stayed `running` for ever** — and the panel's overlay with it. Nothing wrote the end of a job whose time was over, or whose handler threw (a locked database while saving the result, a worker restarted half way). `RunCipiCommand::failed()` closes it: `failed`, `exit_code` 124 for a timeout (1 otherwise), the output the command had printed and one line under it — for a deploy, with the command that says on the server whether it is still running (`cipi deploy <app> --status`). The text of an exception never becomes the output of a job. The dump uploaded for a restore is discarded there too.

### Security

- **A click cannot lock the server out.** The API never sends `--force`, and the CLI ignores it when sudo comes from `www-data`. `lock ssh` is refused until sshd has accepted a login that came over the mesh, while the node key can expire, with UFW off or sshd not listening on the mesh address. `lock http` is refused while an app renews SSL over HTTP-01, when the caller reaches the API on the public address it would close, and — for a panel that runs on the server itself and sends the browser's address as `client` — when the person clicking looks at that panel on the public address. Leaving the network is refused when the caller comes over it.
- **The join key never rests anywhere**: validated (8–512 characters, no spaces, no quotes), quoted, passed as a flag of a synchronous command — never a job, so never in the jobs table — never returned, and replaced by `[key]` should the CLI repeat it in an error.
- **A transfer's passphrase never goes through the API.** The source makes it for that one transfer and hands it to the target's import over SSH, on standard input; no route takes one, returns one, or puts one in the jobs table. The whitelist takes `sync pubkey --raw`, `check`, `trust`, `untrust` and `push` — not `export`, `import` or `list`, which read or leave an archive at a path the caller would name.
- **`sync-manage` is as strong as `ssh-manage`**: whoever holds it can have the server send every app — code, `.env`, databases — to a host of their choosing, and can let another server's `cipi` user sign in here. A token made with a list of abilities has it only once it is named there (`cipi api token create --abilities=…,sync-manage`); a `--full-access` token has it. The host, port and user of a target are checked before they reach a command line.
- **`zt-manage` is as strong as `ssh-manage`**: whoever holds it can put the server on a network of their own. A token made with a list of abilities does not have it until it is named there (`cipi api token create --abilities=…,zt-manage`); a `--full-access` token has it, as it has every ability the API adds.
- The whitelist names each mesh command as the sudoers spell it; nothing of Cloudflare's side was added — `zt enable`, `hostname`, `access`, `lock` stay at the server's shell.

### Notes

- MCP stays read-only for Zero Trust: an agent reads the mesh, it does not join a network or close a port.
- A join installs a package and signs in: it can take a minute or two, within the 300 s the API's PHP-FPM pool and vhost allow.
- On a Cipi older than 5.18.0 the three `mesh*` fields are `null`, `details` has no `mesh`, and the routes under `/api/zt/mesh` answer `503`.

## [1.47.0] - 2026-11-26

For Cipi 5.17.0: the nginx page cache of an app, the PHP of an Octane app by its number, Valkey per app for the whole server, and how notifications read. No new ability: each route sits under one a panel's token already has.

### Added

- **Page cache** (`cipi cache`, Cipi 5.17.0) — nginx keeps the page PHP made and answers the next visitors with it, without running PHP: `fastcgi_cache` for an app on PHP-FPM (Laravel, WordPress, any PHP app), `proxy_cache` for one on Octane. It is not OPcache, which is always on and keeps compiled PHP in memory.
  - `GET /api/apps/{name}/cache` (`nginx-view`): `supported`, `kind`, `enabled`, `active`, `ttl`, `ttl_seconds`, `size`, `clear_on_deploy`, `exclude`, `exclude_max`, `files`, `bytes`, `path`, `suspended`, `clear_command`. `GET /api/cache` lists every app.
  - `PUT /api/apps/{name}/cache` (`nginx-manage`): `{enabled, ttl?, size?, clear_on_deploy?, exclude?}`. On: what is given changes, the rest stays (defaults `10m`, `256m`, emptied after a deploy). Off: the cached pages are deleted. `ttl`, `size`, `clear_on_deploy` and `exclude` with `enabled: false` are a `422`.
  - `PUT /api/apps/{name}/cache/exclude` (`nginx-manage`): `{exclude: ["/cart*", "/feed"]}` — the whole list of the app's own paths that are never cached, on top of what is never cached for any app. `*` is anything, slashes included; without it, the path itself. A path is letters, digits, `. _ ~ % - /` and `*`, 128 characters and 50 paths at most, and is checked before anything runs; `/*` is refused (that is the cache off). Works with the cache off too: the list waits for it. An empty list keeps none.
  - `POST /api/apps/{name}/cache/clear` (`nginx-manage`): the status after it, with `cleared`.
  - Synchronous; `nginx -t` runs before the reload. A Node app, a value the CLI does not take and nginx's refusal are `422` with the CLI's words; an older core is `503` before anything runs.
  - `CipiPageCacheService`, `PageCacheController`, `PageCacheRefusedException`.
- **The PHP of an Octane app** (`cipi frankenphp php|restart`, Cipi 5.17.0), under `php-view` / `php-manage`:
  - `GET /api/apps/{name}/octane`: `php` (Composer, artisan, cron, workers), `octane_php` (inside the FrankenPHP build: what answers requests), `build`, `frankenphp`, `state`, `restart_pending`, `available_php`, and `fix` (`command`, `why`) for a `mismatch`.
  - `PUT /api/apps/{name}/octane/php` `{php: "8.5"}` → job `octane-php`: the build with that PHP — Octane restarted and checked, the previous build back when it does not answer — then the rest of the app.
  - `POST /api/apps/{name}/octane/restart` → job `octane-restart`.
  - An app that is not on Octane is a `422`.
- **Valkey per app** (`cipi valkey`, in the core since 5.9.0; in the API's sudoers from 5.17.0), under `services-view` / `services-manage`:
  - `GET /api/valkey`: `shared_port`, `apps` (`app`, `uses`: `own` / `shared` / `external` / `none`, `port`, `instance`, `memory`, `keys`), `shared` (the apps still on the shared instance) and `isolated`.
  - `POST /api/valkey/isolate` `{app}` or `{all: true}` (`copy: false` to start empty) → job `valkey-isolate`; `POST /api/valkey/share` `{app}` → job `valkey-share`. The job's `result` has `moved`, `left` and `shared_can_be_flushed`.
  - `POST /api/valkey/{name}/restart` — synchronous, the list after it.
  - `POST /api/valkey/flush-shared` — empties the shared instance and changes its password; `409` while an app uses it.
  - **No password leaves the server.** The list has none; the CLI prints the new shared password once, and that output is neither returned nor put in an error. Allowed as `valkey flush-shared --yes`, spelled out: `--force` is not.
  - `CipiValkeyCliService`, `ValkeyController`, `ValkeyRefusedException`.
- **`GET|PUT /api/notifications/settings`** (`notifications-view` / `notifications-manage`, or the `smtp-*` pair) — `timezone` (an IANA name; `null` for the server's own), `server_timezone`, `server_name` (`null` for the hostname), `hostname`, `now`. `PUT` takes one or both; each is checked before the CLI runs (a name is letters, digits, spaces, dots, dashes and underscores, 40 at most). `CipiNotificationsService::settings()`, `updateSettings()`, `settingsError()`.
- **MCP**: `PageCacheShow`, `PageCacheUpdate`, `PageCacheClear`, `OctanePhpShow`, `OctanePhpSwitch`, `ValkeyList`.
- Allowed commands: `cache `, `frankenphp php `, `frankenphp restart `, `notification timezone `, `notification server-name `, `valkey list --json`, `valkey isolate `, `valkey share `, `valkey restart `, `valkey flush-shared --yes`. Job types `octane-php`, `octane-restart` (`php-manage`), `valkey-isolate`, `valkey-share` (`services-manage`).
- OpenAPI 1.47.0: the routes above, tags `Page cache`, `Octane`, `Valkey`, schemas `PageCache`, `PageCacheRequest`, `PageCacheExclude`, `OctanePhp`, `ValkeyList`, `NotificationSettings`, `JobResultOctanePhp`, `JobResultValkeyMove`.

### Notes

- The abilities that already existed now cover more: `nginx-*` the page cache, `php-*` the PHP of an Octane app, `services-*` Valkey per app, `notifications-*` the settings. A token made for one of those pages can do the new things of the same page; nothing has to be created again.
- On a core older than 5.17.0 every new route answers `503` with the version it needs. With the core updated and the API's sudoers not yet (`cipi api update`), the message says so.

## [1.46.0] - 2026-11-26

For Cipi 5.16.0: the whole picture of Cloudflare Zero Trust on `GET /api/zt`.

### Added

- **`details` on `GET /api/zt`** — the API now runs `cipi zt status --json` (Cipi 5.16.0): the tunnel (running here, Cloudflare's status, every connector with its addresses and whether it is this server), the locks, every hostname with its Access `paths` and `allow` list, service tokens (names, targets and client ids — never a secret), webhook bypass, Origin CA and purge after deploy, the panel and the API, SSH and its short-lived certificates, TCP services, previews and what is not on the tunnel yet. The summary fields the endpoint always had (`enabled`, `cloudflared`, `tunnel`, `real_ip`, `lock_http`, `lock_ssh`, `ssh_hostname`) are read from it. An older Cipi prints its text status for the same command: `details` is `null` and the fields are read from `raw`, as before — one call either way. Still read-only (`zt-view`); every change stays on the host CLI.

## [1.45.0] - 2026-11-25

For Cipi 5.15.0: what each notification channel takes, the visitor's address behind Cloudflare from the panel, and a database list that holds databases only.

### Added

- **`events` on a notification channel** — what it takes (Cipi 5.15.0): `null` (everything, the default), or categories (`security`, `deploy`, `backup`, `basic-auth`…), single triggers (`backup_fail`) and `urgent` (failures and security events). Taken by `POST /api/notifications/channels` and `PUT /api/notifications/channels/{id}` — alone too, for a channel whose fields do not change — and passed to the CLI as `--events=` (`--events=all` for `null`, `[]` or a list with `all`). Changing only what SMTP takes neither saves its settings again nor sends a test. `GET /api/notifications` gives every channel its `events` and lists the `categories` (`id`, `label`, `triggers`) and `selectors`; `GET /api/notifications/triggers` gives every trigger its `category_id`. On an older core a request with `events` is refused (`503`, the version it needs) instead of being saved without them. `CipiNotificationsService::eventsFlag()`, `EVENTS_MIN_CIPI`.
- **The visitor's address behind Cloudflare** (`cipi nginx real-ip`, Cipi 5.15.0), on the Firewall routes and ability `firewall-manage`, synchronous, answering with the firewall after the change:
  - `GET /api/firewall` has **`real_ip`**: `enabled`, `provider`, `header`, `kept_by` (`real-ip`, `zero-trust`, `both`, `other`), `ranges`, `refreshed_at`, `nightly_refresh`, `can_disable`, `bans_active`; `null` on an older core;
  - **`PUT /api/firewall/real-ip`** turns it on (`cipi nginx real-ip cloudflare`; on already, Cloudflare's ranges are fetched again), **`POST /api/firewall/real-ip/refresh`** fetches the ranges again, **`DELETE /api/firewall/real-ip`** turns it off (`off --yes`: the client asks first, with what the CLI warns of);
  - **`DELETE` is refused (`409`) when the request itself came through Cloudflare** (`CF-Connecting-IP`): from then on the API would see Cloudflare's address instead of the panel's — outside its own IP whitelist, and an address fail2ban or CrowdSec may ban. That is turned off at the server;
  - the CLI's refusals (a `real_ip` configuration Cipi did not write, nginx refusing the file, Cloudflare's ranges unreachable) are `422` with its words; an older core is `503` before anything runs.
  - Allowed commands: `nginx real-ip status --json`, `cloudflare`, `refresh`, `off --yes` — each spelled out.
- OpenAPI 1.45.0: `/firewall/real-ip`, `/firewall/real-ip/refresh`, `FirewallStatus.real_ip`, `events` on the channel requests.

### Fixed

- **`GET /api/dbs` listed a MySQL client's warning as a database** on a server switched to MySQL: "mysql: [Warning] Using a password on the command line interface can be insecure." Cipi 5.15.0 fixes the cause (the password is no longer on the client's command line, and `cipi db list` keeps stderr apart); the API now drops such a line whatever the core — a client's `[Warning]`/`[Note]`/`[ERROR]` line, or psql's `WARNING:`/`NOTICE:` — so nothing offers to back it up, restore it or change its password. `CipiDatabaseListCliService::withoutClientNoise()`.

## [1.44.0] - 2026-11-25

The firewall, for a core that has it (Cipi 5.14.0): the panel's Firewall tab. The optional packages, installed and removed from the panel. An app's own nginx directives. And a job's output while it runs.

### Added

- **`GET|PUT|DELETE /api/apps/{name}/nginx`** — an app's own nginx directives (`cipi nginx show|set|reset`, Cipi 5.14.0): headers, caching, limits, rewrites, `location` blocks, kept by Cipi and included in the app's server block. `GET` (`nginx-view`) answers `{app, config, bytes, max_bytes, active, suspended}`; `PUT {config}` (`nginx-manage`) replaces the text — empty removes it — and `DELETE` removes it. Synchronous: `nginx -t` runs before nginx reloads, and a refused text is a `422` with `reasons` (`line 2: unknown directive "boom"`), nothing changed.
  - **A subset of nginx's language.** The CLI knows the call comes from the API and takes only the directives of a list (`lib/nginx-lint.sh`): no `access_log`/`error_log` with a path, `include`, `listen`, `server_name`, `*_pass`, certificates; `root` and `alias` only under the app's home. With those a token would be root (nginx opens log paths and reads included files as root) or reach another app. Reverse proxies and redirects stay on their endpoints; the whole language is `cipi nginx edit <app>` at the server.
  - `CipiNginxCliService`, `NginxController`, `NginxConfigRefusedException`; `nginx show|set|reset` among the allowed commands (not `edit`, `upgrade`, `default-server`); abilities `nginx-view`, `nginx-manage`.
- **A running job says what its command has printed so far.** `GET /api/jobs/{id}` on a `running` job carries `output` — its last 50,000 characters, refreshed once a second at most — so a deploy, an install or a backup can be followed while it runs; `result` and `exit_code` still come with the finished job. `CipiCliService::runStreaming()` reads the command's output as it arrives (the result is the one `run()` gives, byte for byte); `CipiJob::recordProgress()` keeps it, and never fails a job.
- **`system.ipv6`** in `GET /api/status` — the server's public IPv6 address, for a domain's AAAA record (every vhost Cipi writes listens on IPv6 too). Read from the interfaces, nothing asked of an outside service: the first global address that is not temporary, deprecated or unique-local; `null` without one.
- **`subject`** on the records of `GET /api/apps/{name}/deploy/audit` — the first line of each deploy's commit message (Cipi 5.14.0 reads it from the app's clone; it is not in the ledger and no hash covers it). Passed through as the CLI gives it.
- **`GET /api/firewall`** — `cipi firewall status --json`, typed: UFW (active, default policies, every rule with `kind`, `family`, `comment`, `managed_by`, `protected`, `removable`), `protected_ports`, the Zero Trust locks, fail2ban (threshold, timings, jails and banned addresses) and CrowdSec (engine, bouncer, allowlist, decisions, `enable_blockers`).
- **`POST|DELETE /api/firewall/rules`** — open a port or a range (`port`, `from`, `proto`), remove what allow (or deny, `action: deny`) made.
- **`POST|DELETE /api/firewall/blocks`** — every port refused to an `address`, ahead of every allow; refused for the address of the request and for ranges wider than /8 (IPv4) or /16 (IPv6).
- **`PUT /api/firewall/attempts`** — failed SSH logins before a fail2ban ban (1–100, `null` for the default).
- **`DELETE /api/firewall/bans`** — lift a ban, fail2ban's and CrowdSec's (`422` when the address is not banned).
- **`POST|DELETE /api/firewall/crowdsec/allowlist`**, and **`POST /api/firewall/crowdsec/enable|disable`** as jobs (`crowdsec-enable`, `crowdsec-disable`). Enabling answers `422` with `blockers` when the server cannot take it, and allowlists the address of the request (`--allow`), so the server never bans the panel that manages it.
- Every change answers with the firewall after it: `{data, message}`. The CLI's refusals — a protected port, a Zero Trust lock, a rule that is not there — are `422` with its words, without colours.
- Abilities **`firewall-view`** and **`firewall-manage`**; job types `crowdsec-enable` and `crowdsec-disable` read back with `firewall-manage`.
- MCP tool **`FirewallStatus`** (read-only). MCP server 1.8.0.
- OpenAPI 1.44.0: tag *Firewall*, the routes, `FirewallStatus`, `FirewallRule`.
- **`POST /api/packages/{package}`** and **`DELETE /api/packages/{package}`** — install and remove one entry of the server's optional packages as jobs (`package-install`, `package-remove`), ability **`packages-manage`**, read back with it. Cipi 5.14.0 widens the catalog to twenty entries (`browsershot`, `fonts-cjk`, `avif`, `pdftk`, `libreoffice`, `pandoc`, `tesseract` join `image-optimizers`, `ffmpeg`, `imagemagick`, `poppler-utils`; the `sec-*` scanners Hackly runs, seven of them upstream releases pinned by version and SHA-256) and lets the panel run `package install|remove`. Before any job: the id must be one of the server's own catalog (`404`), not already installed or not installed (`409`), on a core that allows it (`503`). The commands carry `--yes` and never `--force` or `--autoremove`. Job results: `binaries` and `missing` after an install, `apt` (what was purged) or `still_needed` after a removal; a refusal (a package that depends on the entry) fails the job with the CLI's words.
- `GET /api/packages` returns `kind` (`tools` | `libraries`), `binaries`, `missing`, `source` (`ubuntu` | `upstream`), `version`, `installed_version` and `outdated` per entry; on an older core every entry reads as tools from Ubuntu with both lists empty. An outdated upstream entry may be installed (it updates) and removed. The `package-install` result names the `version` of an upstream entry; the `package-remove` result lists the apt packages only that entry needed. MCP `PackageList` says so; installing stays out of MCP, like the firewall's changes.
- OpenAPI: `POST|DELETE /packages/{package}`, `kind`/`binaries`/`missing`/`source`/`version`/`installed_version`/`outdated`, `JobResultPackageInstall`, `JobResultPackageRemove`.

### Fixed

- **MCP `tools/list` left out the last twelve tools** — `ApiLogShow`, `ServerStatus`, `ServiceList`, `PhpList`, `FrankenPhpStatus`, `FrankenPhpUpgrade`, `IpWhitelistShow` among them: the page held 80 and there are 93. It holds 120; a check in `tests/standalone.php` keeps it above the count.

### Notes

- Needs Cipi 5.14.0 (`cipi self-update`): its `cipi firewall status --json`, `cipi package install|remove` from the panel, and the panel's whitelist. Tokens do not get `packages-manage` by themselves (full-access tokens do). The API never sends `--force`; the CLI ignores it from the panel anyway, so the SSH port, 80 and 443 stay open and Zero Trust locks stay in place whatever a token can do. Not here: `cipi firewall deny` (the panel opens ports and blocks addresses), CrowdSec's rescue listener.

## [1.43.0] - 2026-11-24

What Cipi 5.13.0 adds, read by the panel — and the Access tab that showed raw output on every app.

### Fixed

- **`GET /api/apps/{name}/basicauth` failed on every app whose vhost has no cipi/agent marker**, and the panel showed the CLI's raw output, colour codes included (`[1mBasic auth — <app>[0m ━━━ Status [2mdisabled[0m`). Cipi 5.11.0–5.12.x printed the status, then exited 1 (fixed in Cipi 5.13.0). The status is read from `cipi basicauth status --json` (Cipi 5.13.0); on an older core, a text that holds the status is the answer whatever the exit code. `enable` on those versions — enabled, then exit 1 before the credentials — answers `enabled` with a `notice` to set the password again. Errors of the basic auth routes come back without colour codes.

### Added

- **`.env` order**: `PUT /api/apps/{name}/env` takes `order`, every key of the file once, applied after `set` and `unset` (`cipi app env --order`, Cipi 5.13.0) — the panel's drag and drop. A key twice or not a key: `422` before the CLI runs; a key left out or unknown: `422` with the core's message, the file unchanged; an older core: `422`, nothing sent. `vars` come back in the order of the file. MCP `AppEnvUpdate` takes `order`.
- **Backup `exclude_paths`** on `POST /api/backups/profiles` and `PUT /api/backups/profiles/{name}`: folders and files left out of the app archives (`storage/app/uploads`, `*.mp4`, `shop:storage/app/uploads`). Absolute paths, the whole app (`*`, `shared`, `htdocs`) and shell characters are refused with `422`; an empty list on update clears it. Needs Cipi 5.13.0 (`422` below: an older core would ignore the flag); an empty list sent to an older core passes nothing, since there is nothing to clear.
- **`GET /api/dbs/engines`**: each engine says which server runs on it — `server` (`mariadb` or `mysql` for the engine `mariadb`, `pgsql`) and `label` — from `cipi db engines --json` on Cipi 5.13.0, from the table (and its MySQL line) on an older core. Replacing MariaDB with MySQL stays on the server's CLI (`cipi db switch`): no route.
- **`GET /api/apps/{name}/deploy/status`**: `paused_commit` (Cipi 5.13.0) — the commit whose automatic deploy failed; the webhook does not deploy it again until a new push. The `app-deploy-unlock` job result reports `resumed` / `resumed_commit` when `--unlock` resumed it.
- `GET /api/apps/{name}/basicauth` returns `agent` (`mode`, `public_prefix`) with Cipi 5.13.0.
- OpenAPI 1.43.0.

## [1.42.0] - 2026-11-23

Every notification channel and trigger, not only SMTP, for a core that has them (Cipi 5.12.0).

### Added

- **`GET /api/notifications`** — `cipi notification status --json`: every channel (SMTP first) with type, state and target, how many triggers are on, the channel types. Secrets never come back: `token_set`, `api_key_set`, `secret_key_set`, `secret_set`; a webhook URL with its secret part masked.
- **`POST /api/notifications/channels`** — add a channel: `type` (`smtp`, `resend`, `ses`, `slack`, `discord`, `mattermost`, `googlechat`, `telegram`, `ntfy`, `pushover`, `webhook`), `id` (not for SMTP), the fields of that type, `enabled`, `test`. A field of another type, a missing required one, `http://` for a chat webhook, a recipient that is not an address: `422` before the CLI runs. `409` when the id exists. `201` with `{channel, test}`.
- **`PUT /api/notifications/channels/{id}`** — `cipi notification update`: only the fields given change, a secret left out is kept (`clear_secret` drops a webhook's signing secret), the type cannot change; `enabled` and `test` too.
- **`DELETE /api/notifications/channels/{id}`**, **`POST /api/notifications/channels/{id}/enable|disable`**.
- **`POST /api/notifications/channels/{id}/test`** (`all` for every enabled channel) — `200 {ok: true, message}`, or `422` with the reason when the channel refused it (Cipi 5.12.0 reports it).
- **`GET|PUT /api/notifications/triggers`**, **`POST /api/notifications/triggers/reset`** — every trigger with category, label and state; change many in one write: `triggers` (`{id: bool}`), `on`, `off`, `all`.
- Abilities **`notifications-view`** and **`notifications-manage`**. Tokens with `smtp-view` / `smtp-manage` are accepted on the same routes: the panel's existing tokens keep working.
- MCP tools: `NotificationsShow`, `NotificationChannelSave`, `NotificationChannelTest`, `NotificationChannelRemove`, `NotificationTriggersUpdate`.
- OpenAPI 1.42.0.

### Notes

- Synchronous, like `/api/smtp`: secrets are passed to the CLI and stored in the server's vault; nothing is queued, so they never reach the jobs table.
- On an older core every route answers `503` with `needs Cipi 5.12.0+`. The `/api/smtp` routes are unchanged.

## [1.41.0] - 2026-11-23

Metrics and health history, and the cipi/agent routes behind basic auth, for a core that has them (Cipi 5.11.0).

### Added

- **`GET /api/metrics`** (`metrics-view`) — `cipi metrics overview --json`: the host now; per app the latest values (`cpu`, `mem_mb`, `req`, `http_5xx`, `http_ms`, `http_code`, `fpm_procs`, `fpm_max`, `fpm_saturated`, `workers_up`, `workers_total`), a 5-minute `spark` of the last hour, `uptime` over 24h, 7d and 30d for the apps with a healthcheck; the last 15 events; the collector's state. The one call a dashboard needs.
- **`GET /api/metrics/series`** and **`GET /api/apps/{name}/metrics`** (`metrics-view`) — `cipi metrics show --json`: `metrics` (comma-separated, checked against the host or app list — an unknown name is a `422` that lists the known ones), `range` (`90m`, `24h`, `7d`, `30d`; capped at the rollup retention) or `from`/`to`. About 240 points of `[ts, avg, max]` whatever the range; `source` says whether they come from the per-minute samples or the rollups.
- **`GET /api/metrics/uptime`** (`metrics-view`, optional `app` and `range`, default 30d) — healthcheck uptime %, minutes down, average response time, incidents and uptime per UTC day.
- **`GET /api/metrics/events`** (`metrics-view`; `app`, `source` `monitor|health|deploy|heartbeat`, `range` default 7d, `limit` 1–1000).
- **`GET|PUT /api/metrics/settings`** — read with `metrics-view`; change with `metrics-manage`: `enabled`, `probe`, `raw_days` (1–90), `rollup_days` (up to 400, not shorter than `raw_days`), `resolution_minutes` (5, 10, 15, 30, 60), `events_days` (7–730). Synchronous (`cipi metrics enable|disable`, `probe on|off`, `retention …`); returns the new settings. The status never carries a secret: the heartbeat URL by host, the OpenMetrics token as `token_set`.
- **`PUT|DELETE /api/metrics/heartbeat`**, **`POST /api/metrics/heartbeat/test`** (`metrics-manage`) — `url` (http/https, no spaces) and `every` (1–60 minutes).
- **`GET|PUT /api/apps/{name}/basicauth/agent`** (`apps-basicauth`) — whether the cipi/agent routes are left out of basic auth: `{mode: public|protected, public, prefix}`; `PUT` with `mode`.
- **Abilities** `metrics-view` and `metrics-manage`.
- **MCP tools** `MetricsOverview`, `MetricsSeries`, `MetricsUptime`, `MetricsEvents`, `MetricsSettingsShow`, `MetricsSettingsUpdate` (retention, probe, collector, `heartbeat_url` / `heartbeat_every` / `heartbeat_off`).
- Allow-list: `metrics overview|status|show|uptime|events|retention|probe|enable|disable|heartbeat`, `basicauth agent` — what the Cipi 5.11.0 panel sudoers allow. Not `metrics openmetrics` (it opens a port and firewall rules), `prune` or `reset`: host CLI only.
- OpenAPI: the eleven routes, a `Metrics` tag, version 1.41.0.
- `tests/standalone.php`: 36 more checks.

### Notes

- Every metrics route is synchronous: the CLI reads a local SQLite database and answers in well under a second, so nothing is queued.
- On a core older than 5.11.0 every one of them answers `503` with `Metrics needs Cipi 5.11.0+ — this server runs Cipi … Run: cipi self-update`, before anything runs. A core where `sqlite3` is missing answers `503` with the CLI's own message (`apt-get install -y sqlite3`).

## [1.40.0] - 2026-11-21

JIT per app and the FrankenPHP builds of Octane apps, for a core that has them (Cipi 5.10.0).

### Added

- **`GET /api/apps/{name}/jit`** (`apps-view`) — `cipi app jit <app> --json`: `runtime`, `php` (for an Octane app the PHP inside its FrankenPHP build), `enabled`, `mode`, `buffer`, `supported`, `note`.
- **`PUT /api/apps/{name}/jit`** (`apps-edit`) — `enabled`, and with it `mode` (`tracing`|`function`) and `buffer` (16M–1G): `cipi app jit <app> on|off` as an async job `app-jit`. The CLI checks the PHP starts with JIT before writing anything, restarts PHP-FPM of that PHP version (every app on it) or the app's Octane, and goes back to the previous setting when the app does not come up. The job result is `{app, enabled, mode, buffer}`. A Node app, `mode`/`buffer` with `enabled: false`, a buffer out of range and a core older than 5.10.0 are refused with `422` before a job is queued.
- **`GET /api/frankenphp`** (`php-view`) — `cipi frankenphp status --json`: each Octane app's build, the PHP inside it, `state` (`ok`, `vulnerable`, `mismatch`, `unpinned`, `missing`) and `restart_pending`; the builds on the server; `autoupdate`.
- **`POST /api/frankenphp/upgrade`** (`php-manage`) — optional `app` (an Octane app; without it, all of them): `cipi frankenphp upgrade` as an async job `frankenphp-upgrade`. Never another PHP minor or FrankenPHP major. The job result lists per app `upgraded` (with FrankenPHP and PHP), `up-to-date`, `available_on_other_php`, and `rolled_back`.
- MCP tools `AppJitShow`, `AppJitUpdate`, `FrankenPhpStatus`, `FrankenPhpUpgrade`.
- `CipiCliService::ALLOWED_COMMANDS`: `app jit`, `frankenphp status`, `frankenphp upgrade` (the core's sudoers lists them from 5.10.0). Not `frankenphp use` nor `import`.
- OpenAPI: the four routes, `AppJitResponse`, `AppJitUpdateRequest`, `FrankenPhpStatusResponse`; `info.version` 1.40.0.
- `tests/standalone.php`: 23 more checks (106).

### Changed

- Token ability descriptions: `apps-edit` includes JIT, `php-view` / `php-manage` the FrankenPHP builds.

## [1.39.2] - 2026-11-19

PHP 8.6 can be installed and assigned to an app through the API, on a server whose core knows it (Cipi 5.9.1). The legacy versions a server may still have (7.4–8.2) are reported where they run and refused by name where they cannot be used. No route, ability or response changes.

### Added

- **PHP 8.6** in `php_versions` (`config/cipi.php`): accepted by `POST /api/php/install`, by `php` on app create and app edit, and by the MCP `AppCreate` / `AppEdit` tools. Cipi does not publish this config; an application that published it (`--tag=cipi-config`) keeps its own list — add `'8.6'` there.
- **Refused with `422` on a core older than 5.9.1**, before a job is queued: `PHP 8.6 needs Cipi 5.9.1+ — this server runs Cipi 5.9.0. Run: cipi self-update` (`CipiValidationService::PHP_MIN_CIPI`). That core's `cipi php install` does not know the version, and the job would only fail later with "Invalid PHP version". A core whose version cannot be read is let through: the CLI has the last word.
- OpenAPI: `8.6` in the `php` enum of the app create and edit requests.
- `tests/standalone.php`: 13 more checks (83).

### Fixed

- **`GET /api/status` lists the PHP-FPM of a legacy version that is running.** A server installed before Cipi 4.5.4 can still have PHP 7.4–8.2, with apps on it. `GET /api/php` has always listed them (it reads `cipi php list --json`), while the `php` section of the status only looked for the versions in `php_versions` when it read the host itself, and `cipi status` only printed 8.3 and later. Both now cover 7.4–8.6 (the CLI from Cipi 5.9.1).
- **A legacy version is refused as one.** `php: "8.1"` on app create, app edit or `POST /api/php/install` answered `Invalid PHP version '8.1'. Allowed: …`, the same as a typo. It now says that the version is legacy, that an app already on it keeps it, and that it can no longer be installed or assigned (Deployer 8 needs PHP 8.3+), followed by the allowed ones. Still `422`. `CipiValidationService::PHP_LEGACY`.
- **OpenAPI offered 7.4–8.2** in the `php` enum of the app create and edit requests, which the API has refused since 1.15.0. The enum is 8.3–8.6. The create request no longer documents `8.4` as the default: without `php` the core decides (the server's PHP on Cipi 5.9.1+, 8.5 before).
- **The MCP `AppCreate` tool no longer asks for PHP 8.4 when none is given.** It sent `--php=8.4` on its own, and failed with "PHP 8.4 is not installed" on a server that has another one — every server installed with Cipi 5.9.1 once the repository carries 8.6. Without `php` it now leaves the choice to the core, as `POST /api/apps` does.

### How legacy versions are handled

- In `GET /api/php`, a row of `versions` whose version is not in `installable` is a legacy one: it is installed, `apps` says how many apps run on it, and it cannot be given to another app.
- An app on a legacy version can be edited (`php` is only checked when it changes) and moved to a current one with `PUT /api/apps/{name}` and `php`. It cannot be moved back.
- `POST /api/services/{name}/restart` takes `php8.1-fpm` like any other unit. Removing a version stays on the host (`cipi php remove 8.1`).

### Notes

- What `cipi php install 8.6` installs is whatever the server's PHP repository carries that day. PHP 8.6.0 was released on 19 November 2026; that afternoon packages.sury.org had 8.6.0 without the `redis`, `memcached` and `imagick` extensions, and `ppa:ondrej/php` still a release candidate. Cipi 5.9.1 installs the version without the extensions that are missing, says so in the job output, and adds them with the nightly `cipi php upgrade` once they are packaged.
- The PHP security release of the same day (8.2.36, 8.3.37, 8.4.28, 8.5.13) is the core's business: Cipi 5.9.1 installs it and reports the patch level in `cipi php list --json` (`patch`, `security`, `security_floor`). `GET /api/php` does not pass those three fields on — it returns `version`, `status`, `apps` and `default` as before.
- The package itself was only checked on PHP 8.6.0RC5 — the build at hand; not on 8.6.0 — as far as `tests/standalone.php` and a syntax check of every file go — it has no other test suite.

## [1.39.1] - 2026-11-16

What `GET /api/backups` answers is either a backup status or an error that says why there is none; and the API reports its own version. No route, ability or request changes.

### Fixed

- **`GET /api/backups` never answers 200 without a status.** The answer of `cipi backup status --json` was passed on as it came, whatever it was: an empty object became `{"data": []}`, and a client read "backups are not configured" out of it (cipi/gui 4.2.0 did). The answer must now carry `configured` as a boolean; anything else is a `503` that says so, with the command to read the same on the server.
- **A line printed around the JSON no longer turns a good answer into "returned no JSON".** stderr is read with stdout, so a warning from sudo (`sudo: unable to resolve host …`) before the object, or a notice after it, made `json_decode` fail and the whole Backups page "not available". The object is taken from its first `{` to its last `}`, as the disk and `cipi.yml` services already do. When there really is no JSON, the error quotes what was printed instead.
- **sudo refusing the command is reported as that.** `sudo: a password is required` said nothing about its cause: the rule the panel runs `cipi` through (`/etc/sudoers.d/cipi-api`) lists every command by name, and the backup ones are written by the migration of Cipi 5.9.0. The error now names the command, sudo's own sentence, the file, and how to check it. A warning from sudo next to an error of the CLI is not mistaken for a refusal.

### Added

- **`GET /api/backups` says where its answer comes from**: `host` (the server's hostname) and `config_file` (`/etc/cipi/backup.json`, the file `cipi backup configure` writes and `cipi backup status` looks for). With `configured: false`, that is what tells "never configured" from "configured on another server" or "the panel does not see what a shell sees".
- **`GET /api/status` → `system.api`**: the installed cipi/api release (`1.39.1`; a branch build reads `dev-main`), next to `system.cipi`. Left out when Composer cannot tell. The MCP `ServerStatus` tool returns the same snapshot.
- `tests/standalone.php`: 23 more checks (70) — the status in each shape, sudo's and sudo-rs's refusals, the version.

## [1.39.0] - 2026-11-16

The endpoints for what Cipi 5.9.0 adds, and for what the panel could not do: see when each app was last deployed, manage Node runtimes and backup profiles, show a database as an app's, upload a dump. Everything new answers `503` on a core older than 5.9.0, with the version it needs. Nothing existing changes.

One of these closes a bug report — "deploys started by the webhook do not save how long they took". They do: the duration is in the deploy log's end banner. But `deploy.log` is rotated every night, and a client that read it through `GET /api/apps/{name}/logs?type=deploy` (cipi/gui did) found the banner only until the first rotation. A deploy somebody starts is looked at while it runs; one a push starts, the day after.

A token for an integration that is meant to do everything had to be given every ability, one by one — and stopped being "everything" with the next release that added one. This release gives that role a name: a full-access token. Existing tokens are not changed.

### Added

- **Full-access tokens** — `php artisan cipi:token-create --name=<slug> --full-access` (or `--abilities=*`, or `full-access` in the list) creates a token with Sanctum's wildcard ability `*` instead of a list. Every check in the package goes through `tokenCan()`, which answers yes to a wildcard token: the `ability:` middleware of each REST route, `GET /api/jobs/{id}` (the ability of the job's type) and `/mcp` (`mcp-access`) — and it keeps answering yes for the abilities later versions add. The wildcard stands alone on the token: abilities passed next to it are dropped. From the Cipi CLI: `cipi api token create --full-access` (Cipi ≥ 5.9.0).
- **`cipi:token-list`** shows such a token as `* (full access)`.
- **`GET /api/apps/{name}/deploy/history`** (`apps-view` or `deploy-manage`; `limit` 1–500, default 30) — past deploys and rollbacks, newest first, from the deploy log and its rotated files: `result`, `kind`, `release`, `duration`, `exit`, `trigger`, `branch`, `from_release`, `started_at`, `finished_at`. **`GET /api/deploys/last`** — the last run of every app in one call, `null` for an app never deployed. Both go through `cipi deploy --history <app>` / `cipi deploy --last`, flag first: a core that does not know the flag answers "app not found" to that form and cannot deploy on it.
- **Node runtimes** (ability **`node-runtimes-manage`**) — `POST /api/node/install`, `POST /api/node/upgrade` (optional `major`, `restart`), `PUT /api/node/default` (`major` or `system`), `DELETE /api/node/{major}`: async jobs `node-install`, `node-upgrade`, `node-default`, `node-remove`.
- **Backups** (abilities **`backups-view`**, **`backups-manage`**) — `GET /api/backups` (destinations without keys, profiles with what each covers, last run, `overdue`, `running`, `uncovered`), `GET /api/backups/runs`, `POST /api/backups/run` (starts `cipi backup run --detach`, `202` at once), `POST /api/backups/verify` (job `backup-verify`), `POST /api/backups/profiles`, `PUT|DELETE /api/backups/profiles/{name}`, `POST /api/backups/profiles/{name}/enable|disable`, `POST /api/backups/destinations/test`. Profile fields are validated here with the shapes the CLI and `cipi.yml` use, and every value is quoted before it reaches the command line; what the CLI itself refuses comes back as `422` with its message.
- **Databases linked to an app** — `POST /api/dbs` takes `app`; `POST|DELETE /api/dbs/{name}/link`; `GET /api/dbs` returns `app`, `link` and `size_mb` for each database (asked as JSON on Cipi 5.9.0+, the table parsed as before on an older core); `GET /api/apps` returns each app's `databases`.
- **Dump upload** — `GET /api/dbs/uploads/limits`, `POST /api/dbs/uploads`, `POST /api/dbs/uploads/{id}/chunks`, `DELETE /api/dbs/uploads/{id}`, and `upload_id` on `POST /api/dbs/{name}/restore`. Chunked, so the dump does not have to fit one request; limited in size (`db_upload.max_mb`, default 2 GB), in chunk size and in how much may wait to be restored; `.sql` and `.sql.gz` only, the content checked against the name; stored 0600 in the API's storage and deleted when the restore job ends or after a day.
- **`tests/standalone.php`** — 47 checks of the upload service, the backup flag builder, the deploy-history service and the command allow-list, runnable without `vendor/`.

### Not added, on purpose

- **Backup destinations stay read-only.** `cipi backup configure` and `cipi backup destination add|edit|remove` take an S3 secret key; the only way this API passes a value to the CLI is the command line. They are run on the server, and the API lists and tests what is there. `backup fetch`, `backup key`, `backup prune` and `profile remove --purge` stay there as well.
- No MCP tools for the new endpoints in this release.

### Changed

- **`cipi:token-create`** refuses an empty ability list (the token could do nothing) and says so when an ability is not one of this API version's (`config/cipi.php`, `token_abilities`): it is kept on the token, as before, and grants nothing here.
- **`POST /api/dbs/{name}/restore`** — `file` is required only without `upload_id`.
- **`RunCipiCommand`** deletes the uploaded dump of a `db-restore` job when the job ends, also when it throws.
- **OpenAPI** — `info.version` **1.39.0**; the new paths and tags **Backups**; the authentication paragraph mentions full-access tokens.

### Requirements

- **Cipi ≥ 5.9.0** for everything above (`cipi self-update`; migration 5.9.0 rewrites `/etc/sudoers.d/cipi-api`). A token created with a list of abilities needs the three new ones added — or is a full-access token (`--full-access`), which has them already.

## [1.37.0] - 2026-10-29

A deploy started by a Git push was invisible while it ran: the panel learned of it from the audit ledger once it had ended, and whoever was watching the app saw nothing in between. Cipi 5.8.4 adds `cipi deploy <app> --status`, which reads the deploy log's start/end banners and the live processes and says whether a deploy is in flight whoever started it — webhook, CLI, panel, rollback. This release exposes it. **Cipi CLI ≥ 5.8.4** for the flag; no sudoers change (`cipi deploy *` is already allowed).

### Added

- **`GET /api/apps/{name}/deploy/status`** (ability `deploy-manage`, synchronous) — `state` is `running`, `queued` (a webhook push the per-minute cron has not picked up yet), `interrupted` (a run that never wrote its end banner and nothing is running it — the deploy lock may be stuck) or `idle`; `running` and `phase` (`deploy` while Deployer builds the release, `post-deploy` during the steps, cipi.yml and the healthcheck); `queued` and `locked`; `current` — the run in flight with `trigger` (`webhook`, `cli`, `panel`, `rollback`, or the source a cipi/agent trigger reported), `branch`, `from_release`, `started_at` (ISO 8601 with the server's offset), `elapsed` seconds and `pids`; `last` — how the previous run ended (`result` ok/failed, `kind` deploy/rollback, `release`, `duration`, `exit`, its trigger and times); `log`, the deploy log path. `?tail=N` (0-500) adds the last N lines of the deploy log as `tail`. 404 for an unknown app, 503 with the CLI's message on failure. `CipiDeployStatusCliService`.
- **The call is refused with `503` on a core older than 5.8.4** — `Deploy status needs Cipi 5.8.4+ — this server runs Cipi 5.8.3. Run: cipi self-update` — and the flag is never sent there: on an older core `--status` is unknown to `cipi deploy` and the command would start a deploy. `CipiVersionService` reads `/etc/cipi/version` (`cipi version` as the fallback) and treats an unknown version as too old.
- **MCP `DeployStatus`** — the same answer for an agent (`name`, optional `tail`), read-only.

### Changed

- **MCP server** — version 1.7.0: 77 tools, still one `tools/list` page.
- **OpenAPI** — `info.version` **1.37.0**; the new path, `DeployStatusResponse`, and a **Deploy status** paragraph in the introduction.

### Ecosystem

- cipi/gui 4.1.0 polls this endpoint on an app's page to show a deploy in progress whoever started it — a Git push included — with its trigger and elapsed time, and to refresh the deploy list and log when it ends.

## [1.36.1] - 2026-10-28

Security release. `GET /api/jobs/{id}` was the one route without an ability: any token of the API, however narrow, could read any job's result and CLI output by id — and the output of `app-create`, `db-create` and `db-password` holds a password. Reported privately. No change to what is stored or to any other route. `cipi api update` brings it in, as does the nightly `cipi self-update` / `cipi-api-update` cron; Cipi 5.8.3 keeps the jobs that hold a password for a day instead of fourteen.

### Security

- **`GET /api/jobs/{id}` requires the ability the job was created under** (CWE-862, missing authorization). `app-create` needs `apps-create`, `db-password` `dbs-manage`, `app-deploy` `deploy-manage`, `app-artisan` `apps-artisan`, and so on: the table is `CipiJobAbilities::ABILITIES`, one ability per job type, the same one the route that queued the job enforces. A token without it gets the same `404 Job not found` as an unknown id, so an id alone tells nothing. Every token that could start a job can still poll it: the panel's token has every ability, a CI token reads the deploy it started.
- `CipiJobService::dispatch()` refuses a job type missing from that table (`UnmappedJobTypeException`): a type added without an ability cannot be queued at all, let alone read by everybody.
- MCP `JobShow` is unchanged: `mcp-access` is the one ability of every MCP tool, `DbPassword` included.

### Changed

- **OpenAPI** — `info.version` **1.36.1**; `/jobs/{id}` and the introduction describe the ability and the 404.

## [1.36.0] - 2026-10-27

Per-app environments, covering Cipi 5.8.0: every app carries an `env` label (`production` unless set) and Cipi reads `cipi-<env>.yml` before `cipi.yml`. **Cipi CLI ≥ 5.8.0** for `--env` on `app create` / `app edit` and for `env` in the `yml plan` / `yml auto status` JSON; on older servers the API answers `env: production` everywhere (the projection has no key) and clients must not send `env`. No sudoers change: `app create *`, `app edit *` and `yml *` are already allowed.

### Added

- **`env` on `POST /api/apps`** — optional environment label, passed as `cipi app create --env=<label>` only when the client sends one. Validated before the job is queued with the CLI's own rule — lowercase letters, digits, `-` and `_`, 32 characters at most (`^[a-z0-9][a-z0-9_-]{0,31}$`) — and refused with 422 otherwise (`CipiValidationService::envError()`). MCP `AppCreate` takes the same field.
- **`env` on `PUT /api/apps/{name}`** — `cipi app edit --env=<label>`, same validation. A value equal to the current one is dropped like the other edit fields; an app without the key counts as `production`. MCP `AppEdit` takes the same field.
- **`env` in `GET /api/apps` and `GET /api/apps/{name}`** — read from the apps.json projection (`CipiValidationService::getAppEnv()`), `production` when the key is missing. Same field in MCP `AppList` / `AppShow`.
- **`env` in `GET /api/apps/{name}/yml`** and in the `yml/auto/enable|disable` responses — the environment the plan was read for, as `cipi yml plan --json` / `cipi yml auto status --json` print it, `production` when an older core prints none. MCP `AppYmlShow` / `AppYmlAuto` return it too.
- **`env` in the `app-create` job result** when the CLI prints an `Env:` line; an env change on `app-edit` shows under `changes` like the other fields.

### Changed

- **MCP server** — version 1.6.0: `AppCreate` and `AppEdit` gain `env`; `AppList`, `AppShow`, `AppYmlShow` and `AppYmlAuto` return it. Still 76 tools, one `tools/list` page.
- **OpenAPI** — `info.version` **1.36.0**; `env` on `AppCreateRequest`, `AppEditRequest`, `AppListResponse`, `AppShowResponse`, `YmlPlanResponse`, `YmlAutoResponse` and `JobResultAppCreate`; the app create/edit and cipi.yml descriptions mention the per-environment file.

### Compatibility

- Cipi 5.8.0 also adds `deploy.branch` to the cipi.yml schema (the branch the app deploys, validated and applied like `cipi app edit --branch`). The API does not parse cipi.yml itself: a branch change shows in the plan's `changes` like any other key.

## [1.35.0] - 2026-10-22

cipi.yml over the API. Requires **Cipi CLI ≥ 5.6.4** (`cipi self-update`, migration 5.6.4): it adds `cipi yml plan --json` and `cipi yml auto status --json`, and lets `/etc/sudoers.d/cipi-api` run `cipi yml plan *`, `cipi yml apply *` and `cipi yml auto *`. On older servers the new endpoints answer 503; every other route is as in 1.34.0. Cipi 5.6.4 is also the release where auto-apply finally works on Git-webhook deploys.

### Added

- **`GET /api/apps/{name}/yml`** — what `cipi yml plan <app> --json` prints: `file` (the cipi.yml of the live release, null when it has none), `auto` (applied after every successful deploy), `valid` with `errors` and `warnings`, `changes` (`kind`, `summary`), `blockers` (apply refuses while any is listed), `notes` and `in_sync`. Synchronous and read-only. Ability `yml-view`.
- **`POST /api/apps/{name}/yml/apply`** — `cipi yml apply <app> --yes` as an async job `app-yml-apply`. The result has `outcome` (`applied`, `unchanged`, `partial`, `blocked`, `invalid`, `no-file`), `applied`, `failed`, `failures`, `blockers` and `errors`; the last four outcomes end the job as `failed` and still carry the result. Ability `yml-manage`.
- **`POST /api/apps/{name}/yml/auto/enable`** and **`…/disable`** — `cipi yml auto <app> on|off`, synchronous and idempotent; they return `{app, auto, sudoers, file}`. With auto-apply on, whoever can commit to the repository controls the app's server configuration, so clients should confirm before turning it on. Ability `yml-manage`.
- **Token abilities** `yml-view` and `yml-manage` in `config/cipi.php`. Existing tokens do not get them: create a token with them (`cipi api token create`) to use the new endpoints.
- **MCP** — `AppYmlShow` (read-only), `AppYmlApply` (job), `AppYmlAuto` (`enabled`).
- **CLI whitelist** — `yml plan`, `yml apply`, `yml auto` in `CipiCliService::ALLOWED_COMMANDS`.

### Changed

- **Job results** — when a parsed result has an `app` key the CLI text could not fill, it is taken from the job's parameters.
- **MCP server** — version 1.5.0; 76 tools, still one `tools/list` page.
- **OpenAPI** — `info.version` **1.35.0**; 4 new paths under the new `cipi.yml` tag, schemas `YmlPlanResponse`, `YmlAutoResponse`, `JobResultAppYmlApply`; `app-yml-apply` among the job types.

## [1.34.0] - 2026-10-15

Covers Cipi 5.6.0 on the app create path: the asset build can be set at creation, and the job results say whether the app can read its repository. **Cipi CLI ≥ 5.6.0** fills both; on older servers `node_build` is ignored by the CLI and `repository_access` is simply absent. No sudoers change: `app create *` and `app edit *` are already allowed.

### Added

- **`node_build` on `POST /api/apps`** (Laravel apps) — the asset build Cipi runs inside every deploy, after `composer install` and before the release goes live, e.g. `npm ci && npm run build`. Passed as `cipi app create --node-build=…`; the same setting as `node_build` in `PUT /api/apps/{name}/deploy-config`. Validated before the job is queued with the CLI's own rule: it starts with npm, npx, yarn, pnpm, bun or node, `&&` and `;` are allowed, pipes, redirects, backticks and `$( )` are not, 200 characters at most. Refused with 422 on custom and Node apps (Node apps keep `build`). MCP `AppCreate` takes the same field.
- **`repository_access` in job results** — Cipi 5.6.0 tests the deploy key against the repository (`git ls-remote` as the app user, branch included) on app create and on app edit with a new repository or branch. The `app-create` result, and the `app-edit` result when the repository or branch changed, now report it: `status` (`ok`, `nobranch` or `denied`), `branch`, `error` (the line git printed) and `hint` (the CLI fix Cipi suggests, e.g. `cipi git github-token <token> && cipi git refresh myapp`). The check never blocks the job.
- **`build` in the `app-create` result** — the Laravel asset build, or the Node app's build command.

### Changed

- **MCP server** — version 1.4.0 (`AppCreate` gains `node_build`; its description mentions `repository_access`).
- **OpenAPI** — `info.version` **1.34.0**; `AppCreateRequest.node_build`, new schema `RepositoryAccess`, `build` and `repository_access` on `JobResultAppCreate`, `repository_access` on `JobResultAppEdit`.

### Compatibility

- Cipi 5.6.0 also realigns its text tables (`cipi db list` and others) to the widest value of each column. The only table the API parses as text, `cipi db list`, is read with any amount of whitespace between columns, so `GET /api/dbs` is unchanged. Every other read goes through `--json`.

## [1.33.0] - 2026-10-09

Disk usage over the API — the last host insight that was CLI-only. Requires **Cipi CLI ≥ 5.5.2** (`cipi self-update`, migration 5.5.2) so `/etc/sudoers.d/cipi-api` allows `cipi disk` and `cipi disk *`; the command itself exists since 5.5.0. Nothing changes for older servers: the two new endpoints answer 503 there, every other route is as in 1.32.0.

### Added

- **`GET /api/disk`** — what `cipi disk --json` prints: the filesystem `/home` is on (`mount`, `size_gb`, `used_gb`, `free_gb`, `used_percent`), every app largest first with `files_gb`, `database_gb`, `total_gb`, `percent` of the disk, the KiB figures, the soft limit (`limit_gb`, `limit_percent`, `over_limit`), and the `apps_total_gb` / `apps_percent` / `other_gb` / `other_percent` totals. Synchronous; sizes are measured on request, so a server with large apps takes a few seconds. Ability `disk-view`.
- **`GET /api/disk/dbs`** — what `cipi disk db --json` prints, as one entry per installed engine (`mariadb`, `pgsql`, `valkey`, `meilisearch`): `databases` (`name`, `size_mb`, plus `keys` for Valkey and `documents` for Meilisearch), `on_disk_mb`, `memory_mb` (Valkey), `note` when the engine did not answer. Ability `disk-view`.
- **Token ability** `disk-view` in `config/cipi.php` (`cipi api token create` lists it).
- **MCP** — `DiskUsage`, `DiskDatabases` (read-only).
- **CLI whitelist** — `disk` in `CipiCliService::ALLOWED_COMMANDS`.

### Changed

- **MCP server** — version 1.3.0; 73 tools, still one `tools/list` page.
- **OpenAPI** — `info.version` **1.33.0**; 2 new paths, 2 new schemas; the `Server` tag covers disk usage.

## [1.32.0] - 2026-10-07

Covers Cipi 5.5.0 → 5.5.1: soft disk limit per app, several Cloudflare accounts for DNS-01, and wildcard certificate options. The commands are already in `/etc/sudoers.d/cipi-api` (`app limits *`, `ssl install *`, `ssl dns *`), so no sudoers migration is needed; **Cipi CLI ≥ 5.5.0** is required for `disk_limit_gb` and `/ssl/dns`, **≥ 5.5.1** for `http` / `wildcard: false` on SSL install.

### Fixed

- **`GET /api/apps`** did not return `custom`, so clients showed custom apps as Laravel. List and show now return `custom` (normalized boolean) and a new `type` field: `laravel`, `custom` or `node`. Cipi stores `custom: true` on Node apps too, so `type` is the field to tell them apart. The list also returns `docroot`.
- **MCP `AppList` / `AppShow`** — same `type` / `custom` fields, plus `node`, `node_mode`, `node_version` (they were only in the REST responses).

### Added

- **Limits** (`cipi app limits`):
  - `GET /api/apps/{name}/limits` — `fpm_max_children`, `memory_limit`, `octane_workers`, `worker_procs` (null = CLI default), the defaults, and `disk_limit_gb`. Ability `apps-view`.
  - `PUT /api/apps/{name}/limits` — any of those fields, plus `disk_limit_gb` (GB, files + database, at most two decimals; `null` removes it). Async job `app-limits`; the result reports `disk_used_gb`, `disk_percent`, `over_limit`. Node apps accept only `disk_limit_gb`. Ability `apps-edit`.
  - The disk limit is soft (Cipi 5.5.0): the monitor check `app_disk` warns at 90% and alerts when over; nothing is blocked.
- **DNS-01 / wildcard SSL** (Cipi ≥ 5.5.0):
  - `POST /api/apps/{name}/ssl` accepts an optional body `{ dns: "cloudflare", account, wildcard, http }`. Without a body Cipi ≥ 5.5.1 reissues a DNS-01 certificate over DNS-01 with its own account.
  - `GET /api/ssl/dns` — Cloudflare accounts and the certificates that renew with each (`ssl dns list --json`).
  - `PUT /api/ssl/dns` — `{ name?, token }` adds an account or rotates its token. Synchronous, so the token is never queued or stored by the API; it is never returned.
  - `DELETE /api/ssl/dns/{account}` — refused with 409 while a certificate still renews with the account.
  - All with ability `ssl-manage`. The `ssl-install` job result now includes `challenge` (`http-01` / `dns-01`), `dns`, and `account`.
- **MCP** — `AppLimitsShow`, `AppLimitsUpdate`, `SslDnsList`; `SslInstall` accepts `dns`, `account`, `wildcard`, `http`. Account tokens are deliberately not settable over MCP.
- **CLI whitelist** — `app limits`, `ssl dns list|set|remove` in `CipiCliService::ALLOWED_COMMANDS`.

### Changed

- **Dependencies** — `laravel/framework` `^12.41.1 || ^13.0`, `laravel/sanctum` `^4.3.1` (first release with Laravel 13 support), suggested `laravel/mcp` `^1.0` (new installs already get 1.0.x; tool names and `tools/list` are unchanged).
- **MCP server** — version 1.2.0; 71 tools, still one `tools/list` page.
- **OpenAPI** — `info.version` **1.32.0**; 3 new paths, 6 new schemas. The `JobStatusResponse` type enum and result `oneOf` now also list `node-restart`, `app-fix-permissions` and `app-limits`.

## [1.31.0] - 2026-09-17

Covers Cipi 5.1.1 → 5.4.0: app/path redirects, prefix proxies, Node apps and runtimes, deploy audit ledger, Meilisearch, packages/monitor/Zero Trust status, fix-permissions, and wildcard domains. Requires **Cipi CLI ≥ 5.4.1** (`cipi self-update`, migration 5.4.1) so `/etc/sudoers.d/cipi-api` allows `redirect *`, `proxy *`, and `node list|status|restart`; the read-only search/package/monitor/zt entries exist since their feature releases.

### Added

- **Redirects** (`cipi redirect`, Cipi ≥ 5.3.1) — synchronous (the CLI regenerates the vhost, runs `nginx -t`, and reverts on failure):
  - `GET /api/apps/{name}/redirects` — whole-app redirect + path redirects (`redirect list --json`). Ability `redirects-view`.
  - `PUT /api/apps/{name}/redirect` — body `{ to, code?, keep_path? }` → `redirect set` (every hostname redirects in one hop; ACME stays public; app-served targets refused as loops).
  - `POST /api/apps/{name}/redirect/enable|disable` — toggle the saved redirect without forgetting the target.
  - `DELETE /api/apps/{name}/redirect` — `redirect unset`.
  - `POST /api/apps/{name}/redirects` — body `{ from, to, code?, keep_path? }` → `redirect add` (a `from` ending in `/` is a prefix match). `DELETE` with `{ from }` removes.
  - Ability `redirects-manage` for all writes. MCP: `RedirectList`, `RedirectSet`, `RedirectToggle`, `RedirectUnset`, `RedirectAdd`, `RedirectRemove`.
- **Proxies** (`cipi proxy`, Cipi ≥ 5.3.1):
  - `GET /api/apps/{name}/proxies` (`proxies-view`), `POST` with `{ prefix, upstream, strip_prefix?, preserve_host?, timeout?, buffering? }` and `DELETE` with `{ prefix }` (`proxies-manage`).
  - The CLI loopback guard is **never bypassed from the API** (no `--force`): upstreams on ports Cipi already uses (nginx, SSH, MariaDB, PostgreSQL, Valkey, Meilisearch, other apps' Octane/Reverb) are refused. MCP: `ProxyList`, `ProxyAdd`, `ProxyRemove`.
- **Node apps + runtimes** (Cipi ≥ 5.4.0):
  - `POST /api/apps` accepts `node` (`spa|static|ssr`), `framework` (`next|nuxt|sveltekit|astro|remix|vite`), `node_version`, `build`, `start`, `output`, `health_path`. Node apps require a repository and refuse `custom`/`octane`/`engine`/`php`.
  - `PUT /api/apps/{name}` accepts the same Node fields; `node_version` also pins a **Laravel** app to a Node major (`default` follows the server default again).
  - `GET /api/node` — installed runtimes with server default and apps per major (`node-view`). `GET /api/apps/{name}/node` — app Node status from apps.json (`node-view`). `POST /api/apps/{name}/node/restart` — blue/green restart, async job `node-restart` (`node-manage`).
  - App list/show expose `node`, `node_mode`, `node_version`. MCP: `NodeRuntimes`, `NodeStatus`, `NodeRestart`.
- **Deploy audit** (Cipi ≥ 5.4.0) — `GET /api/apps/{name}/deploy/audit?days=90` returns the app's hash-chained ledger records (`deploy <app> --audit --json`): event, release, commit, origin (cli/panel/webhook/ssh/cron/…), operator, IP, `claimed`. Empty list when the ledger does not exist yet. Ability `deploy-manage`. MCP: `DeployAudit`.
- **Search / Meilisearch** (Cipi ≥ 5.2.2) — `GET /api/search` (`search status --json`, ability `search-view`); `POST /api/apps/{name}/search/enable|disable` (ability `search-manage`, Laravel apps only). Engine install/upgrade/key-rotate/remove stay on the host CLI (not in the API sudoers). MCP: `SearchStatus`, `SearchEnable`, `SearchDisable`.
- **Packages** (Cipi ≥ 5.2.2) — `GET /api/packages` — allowlisted optional host packages with install state (`package list --json`). Read-only; ability `packages-view`. MCP: `PackageList`.
- **Monitor** (Cipi ≥ 5.3.0) — `GET /api/monitor` — system monitor checks with config, state, and last alert (`monitor list --json`). Read-only; ability `monitor-view`. MCP: `MonitorList`.
- **Cloudflare Zero Trust** (Cipi ≥ 5.3.0) — `GET /api/zt` — parsed `zt status` (enabled, cloudflared, tunnel, real_ip, locks, SSH hostname) plus raw output. Read-only by design (the sudoers allow `zt status` only); ability `zt-view`. MCP: `ZtStatus`.
- **Fix permissions** (Cipi ≥ 5.2.1) — `POST /api/apps/{name}/fix-permissions` — restore the app home permission model (ownership, 750 home, 700 `.ssh`, 640 `shared/.env`, log ACLs). Async job `app-fix-permissions`; ability `apps-edit`. MCP: `AppFixPermissions`.
- **Wildcard domains** (Cipi ≥ 5.1.1) — `*.example.com` accepted as primary domain on app create/edit (REST + MCP).
- **App list/show** expose `redirect`, `redirects`, `proxies` from apps.json alongside the new Node fields.
- **Token abilities** — `redirects-view/manage`, `proxies-view/manage`, `node-view/manage`, `search-view/manage`, `packages-view`, `monitor-view`, `zt-view` in `config/cipi.php`.
- **CLI whitelist** — `redirect *`, `proxy *`, `node list|status|restart`, `search status|list|enable|disable`, `package list`, `monitor list`, `zt status`, `app fix-permissions` in `CipiCliService::ALLOWED_COMMANDS`.
- **Job result parsing** — `node-restart` (`{ app, restarted }`) and `app-fix-permissions` (`{ app, fixed }`) in `CipiOutputParser`.

### Changed

- **MCP server** — 20 new tools registered (65 total, one `tools/list` page); instructions mention Node apps, redirects, proxies, search, deploy audit, and monitor.
- **OpenAPI** — `info.version` **1.31.0**; 16 new paths, 20 new schemas; `AppCreateRequest` / `AppEditRequest` / app list-show schemas extended with Node and routing fields.

## [1.20] - 2026-08-06

Version Fix

## [1.19.1] - 2026-08-06

### Fixed

- **`GET /api/dbs/engines`** — treat CLI em dash / `not_installed` status as absent (Cipi ≥ 5.0.14 prints `not_installed`; older builds used `—`).
- **`GET /api/status` host fallback** — PostgreSQL unit detection uses `systemctl cat` instead of `list-unit-files --quiet` (which always exits 0).

### Changed

- **OpenAPI** — `info.version` **1.19.1**.

## [1.19.0] - 2026-08-06

### Removed

- **`PUT /api/php/default`** — system default PHP switch (`cipi php switch`) removed from the API. Use the CLI on the host if needed.
- **`DELETE /api/php/{version}`** — PHP version removal removed from the API. Use the CLI on the host if needed.
- **`DELETE /api/dbs/{name}`** — database deletion (and MCP `DbDelete`) removed from the API. Use the CLI on the host if needed.
- **`PUT /api/dbs/engines/default`** — set default database engine removed from the API. Use the CLI on the host if needed.
- Token ability **`dbs-delete`** removed.

### Changed

- **OpenAPI** — `info.version` **1.19.0**.
- Token abilities — `php-manage` is install-only; `dbs-manage` no longer covers setting the default engine.

## [1.18.0] - 2026-08-06

### Fixed

- **`PUT /api/php/default`** — `php switch` was missing from `CipiCliService::ALLOWED_COMMANDS`, so the endpoint always failed with `Command not allowed: php switch '…'`. Requires **Cipi CLI ≥ 5.0.9** (`cipi self-update`) so `/etc/sudoers.d/cipi-api` allows `php switch` (otherwise sudo asks for a password without a TTY).

### Changed

- **OpenAPI** — `info.version` **1.18.0**.

## [1.17.0] - 2026-08-06

### Added

- **`PUT /api/php/default`** — set the system default PHP version (sync; wraps `cipi php switch`). Body `{ "version": "8.5" }`. Returns updated `GET /api/php` payload. Ability `php-manage`.

### Changed

- **OpenAPI** — `info.version` **1.17.0**.

## [1.16.0] - 2026-08-06

### Fixed

- **`POST /api/php/install` / `DELETE /api/php/{version}` / app PHP checks** — `CipiValidationService::isPhpInstalled()` no longer calls `is_file()` / `is_dir()` on `/usr/bin` or `/etc/php` under the API FPM `open_basedir` (those paths are outside the allowlist). Laravel turned the resulting warnings into `ErrorException`, so the GUI showed a bare **Server Error**. Detection now skips host stats outside the basedir and falls back to a shell `test`.
- **`POST /api/php/install` / `PUT /api/smtp`** — uncaught exceptions now return JSON `{ "error": "…" }` instead of a generic **Server Error** when `APP_DEBUG=false`.
- **open_basedir** — API exception handler maps open_basedir `ErrorException`s to `503` with an actionable message (update to 1.16+).

### Changed

- **OpenAPI** — `info.version` **1.16.0**.

## [1.15.0] - 2026-08-05

Server management, SMTP/healthchecks, IP whitelist, and safer app edit. Requires **Cipi CLI ≥ 5.0.6** (`cipi self-update`) for webhook recreate, PHP/SSH/service JSON listings, SMTP `--json`, `cipi api ip-whitelist`, and updated API sudoers.

### Added

- **`POST /api/apps/{name}/webhook/recreate`** — recreate GitHub/GitLab webhook; body `{ "rotate_secret": true }` also rotates `CIPI_WEBHOOK_TOKEN`. Async job (`app-webhook-recreate`). Ability `apps-edit`.
- **PHP** — `GET /api/php`, `POST /api/php/install`, `DELETE /api/php/{version}` (abilities `php-view` / `php-manage`).
- **DB engines** — `POST /api/dbs/engines/install`, `PUT /api/dbs/engines/default` (ability `dbs-manage`).
- **SSH keys** — `GET|POST /api/ssh/keys`, `DELETE /api/ssh/keys/{n}` (abilities `ssh-view` / `ssh-manage`).
- **Services** — `GET /api/services`, `POST /api/services/{name}/restart` (abilities `services-view` / `services-manage`).
- **SMTP** — `GET|PUT|DELETE /api/smtp`, `POST /api/smtp/enable|disable|test` (abilities `smtp-view` / `smtp-manage`). Password never returned on GET.
- **Healthchecks** — `GET /api/health`, `GET|PUT|DELETE /api/apps/{name}/health`, `POST /api/apps/{name}/health/check` (abilities `health-view` / `health-manage`).
- **IP whitelist middleware** — `cipi.ip` on `api/*` and `/mcp`. Reads `/etc/cipi/api-ip-whitelist` (`CIPI_API_IP_WHITELIST`). Missing file or `*` = allow all; otherwise IPv4/IPv6/CIDR. Rejected clients get `403` `{ "error": "IP not allowed", "ip": "…" }`.
- **IP whitelist REST** — `GET /api/ip-whitelist`, `PUT /api/ip-whitelist` (`entries`, optional `ensure_client_ip`), `POST /api/ip-whitelist` (`ip`), `DELETE /api/ip-whitelist` (`ip`), `POST /api/ip-whitelist/allow-all`. Abilities `ip-whitelist-view` / `ip-whitelist-manage`. PUT auto-adds the caller IP when restricting (unless `ensure_client_ip: false`).
- **MCP** — `AppWebhookRecreate`, `PhpList`, `IpWhitelistShow`.

### Changed

- **`PUT /api/apps/{name}`** — only forwards fields that differ from the current app (prevents no-op webhook/deploy-key recreation); PHP must be installed on the host (422 otherwise).
- **PHP allowlist** — installable versions tightened to `8.3`, `8.4`, `8.5` (matches Cipi CLI / Deployer 8).
- **OpenAPI** — `info.version` **1.15.0**.

## [1.14.0] - 2026-08-05

App `.env` / `auth.json` / Artisan, whitelisted `app run`, and structured deploy config. Requires **Cipi CLI ≥ 5.0.3** (`cipi self-update`) for non-interactive flags and sudoers whitelist (`app env`, `app artisan`, `app run`, `auth *`, deploy-config).

### Added

- **`.env` API** — `GET|PUT /api/apps/{name}/env` (ability `apps-env`): list variables; merge with `{ "set": {…}, "unset": […] }`. Sync. MCP: `AppEnvShow`, `AppEnvUpdate` (secrets redacted).
- **`auth.json` API** — `GET|POST|PUT|DELETE /api/apps/{name}/auth` (ability `apps-auth`): shared Composer/structured JSON under `shared/auth.json`. Distinct from HTTP Basic Auth (`apps-basicauth`). MCP: `AppAuthJson*`.
- **Artisan REST** — `POST /api/apps/{name}/artisan` with `{ "command": "…" }` → `202` job (`app-artisan`); poll `GET /api/jobs/{id}` for `output` / `exit_code`. Ability `apps-artisan`. MCP `AppArtisan` remains synchronous.
- **`POST /api/apps/{name}/run`** — body `{ "command": "composer install --no-dev" }` → `202` job (`app-run`); poll `GET /api/jobs/{id}` for output. Ability `apps-run`.
- **`GET /api/run-commands`** — list allowed binaries (composer, npm/npx/yarn/pnpm, ls/ll, cat/head/tail, mkdir/cp/mv/rm/…, tar/zip, git, php, node, find, …).
- **Guards** — no editors/pagers/shells/REPLs (`nano`, `vim`, `less`, `bash`, `tinker`, …); no interactive flags (`tail -f`, `git -i`, `php -a`, `node -e`, …); no shell metacharacters / `..` path traversal.
- **`GET|PUT /api/apps/{name}/deploy-config`** — structured Deployer recipe options (`keep_releases`, migrate/optimize/storage_link/queue_restart/horizon_terminate, `node_build`, `predeploy_snapshot`, `extra_artisan`). Regenerates `deploy.php` from template (not a raw PHP upload). Ability `apps-deploy-config`.
- **Token abilities** — `apps-env`, `apps-auth`, `apps-artisan`, `apps-run`, `apps-deploy-config` in `config/cipi.php`.
- **CLI whitelist** — `app env`, `auth create|edit|show|delete` in `CipiCliService::ALLOWED_COMMANDS`.
- **MCP** — `AppRun`, `AppRunCommands`, `AppDeployConfigShow`, `AppDeployConfigUpdate`.

### Changed

- **OpenAPI** — `info.version` **1.14.0**; paths/schemas for env, auth.json, artisan, app-run, deploy-config; job types `app-artisan`, `app-run`.

## [1.13.0] - 2026-08-03

Optional Laravel Octane (FrankenPHP) on app create — requires Cipi **5.0+**.

### Added

- **App create `octane`** — `POST /api/apps` accepts `octane: true` or `octane: "frankenphp"` (Laravel only; rejected with `custom`). Dispatches `cipi app create --octane=frankenphp`. MCP `AppCreate` updated.
- App list/show expose **`octane`** and **`octane_port`** from `apps-public.json`

### Changed

- **OpenAPI** — `info.version` bumped to **1.13.0**; `AppCreateRequest.octane`, list/show octane fields

## [1.12.1] - 2026-08-03

Home page noindex and PostgreSQL in server status when installed.

### Added

- **Home robots** — welcome page meta `noindex, nofollow`, `X-Robots-Tag` on `/`, and `/robots.txt` (`User-agent: *` / `Disallow: /`)

### Fixed

- **`GET /api/status`** — host-read fallback includes **`postgresql`** when the systemd unit is installed (same as `cipi status` on Cipi 4.8+); CLI-parsed snapshots already surfaced it when present

### Changed

- **OpenAPI** — `info.version` bumped to **1.12.1**; status `services` example includes `postgresql`

## [1.12.0] - 2026-08-02

Cipi 4.8 support: www/apex redirects, force HTTPS, and multi-engine databases (MariaDB + PostgreSQL).

### Added

- **WWW redirects** — REST endpoints under `/api/apps/{name}/www` wrapping [`cipi www`](https://cipi.sh/docs/infrastructure#www):
  - `GET …/www` — sync status (`primary`, `apex`, `www`, `redirect`)
  - `POST …/www/add` — add counterpart host alias
  - `POST …/www/force-to-root` / `force-from-root` — canonical 301 redirects
  - `POST …/www/clear` — clear redirect state
  - Token ability **`www-manage`**; MCP tools `WwwStatus`, `WwwAdd`, `WwwForceToRoot`, `WwwForceFromRoot`, `WwwClear`
- **`POST /api/apps/{name}/ssl/force`** — re-apply HTTP → HTTPS redirect without new issuance (`cipi ssl force`). MCP tool `SslForce`
- **Multi-engine databases** — optional `engine` (`mariadb`|`pgsql`) on create/list/delete/backup/restore/password; **`GET /api/dbs/engines`** lists installed engines and default. MCP tool `DbEngines`
- **App create `engine`** — Laravel apps can select DB engine at create time (`POST /api/apps`, MCP `AppCreate`)
- App list/show expose **`engine`**, **`www_redirect`**, and **`force_https`** from `apps-public.json` / apps metadata

### Changed

- **`CipiCliService`** — allows `www *`, `ssl force`, and `db engines`
- **`CipiOutputParser`** — parses Cipi 4.8 `db list` / `db engines` / www / ssl force output (keeps legacy db list shape)
- **`db delete` / `db restore`** jobs pass `--force` for non-interactive API use
- **OpenAPI** — `info.version` bumped to **1.12.0**

## [1.11.12] - 2026-07-01

Reliable paginated app logs under `open_basedir` and accurate app suspend status.

### Fixed

- **Laravel log paths** — always read `shared/storage/logs/*.log` for non-custom apps instead of gating on a sudo directory probe that could fail while log files exist.
- **Paginated log parsing** — scan `===CIPI_LOG_FILE:` markers linearly so stack traces cannot break regex-based block splitting; parse file paths with `strrpos` on the line-count suffix.
- **`open_basedir`** — paginated app logs are fetched via `sudo cipi app logs read` (CLI runs as root) because cipi-api PHP cannot read `/home/*` directly; falls back to `cipi-read-app-logs` when CLI output is empty.
- **`isSuspended()`** — treats JSON string `"true"` / `"1"` as suspended (`apps.json` can store booleans as strings).
- **`GET /api/apps` / `GET /api/apps/{name}`** — `suspended` reflects live status via `isSuspended()` instead of a stale `apps.json` value.

### Changed

- **`GET /api/apps/{name}/logs`** — CLI-first paginated reads (`cipi app logs read`); optional `warnings` when no output; sudo fallback via `cipi-read-app-logs` or inline bash.
- **`availableTypes()`** — always exposes `laravel` for managed apps.
- **`CipiCliService`** — allows `app logs read` for the log snapshot command.
- **`CipiLogReader`** — refactored paginated tail (`tailPaginatedViaSudo`, `parsePaginatedOutput`, local read helpers).
- **OpenAPI** — `info.version` bumped to **1.11.12**.

## [1.11.11] - 2026-06-30

PHP 8 compatibility for paginated app logs.

### Fixed

- **`GET /api/apps/{name}/logs`** — parenthesized ternary/`?:` chain when splitting redacted log lines (PHP 8+ fatal: `Unparenthesized a ? b : c ?: d`).

### Changed

- **OpenAPI** — `info.version` bumped to **1.11.11**.

## [1.11.10] - 2026-06-30

No changes

## [1.11.9] - 2026-06-30

Paginated app logs over REST.

### Added

- **`GET /api/apps/{name}/logs`** — synchronous, paginated log snapshots for nginx, PHP-FPM, Laravel (when present), worker, and deploy logs. Query params: `type` (default `all`), `page` (default `1`, most recent first), `per_page` (default `50`, max `1000`). Requires `apps-view` ability. Matches `cipi app logs` paths; log text is redacted via `McpProductionContent`.
- **`CipiLogReader::tailViaSudoPaginated()`** — page-based reads from the end of each log file via sudo.
- **`CipiAppLogsService::readPaginated()`** and **`availableTypes()`** — structured JSON payload for REST.

### Changed

- **OpenAPI** — `info.version` bumped to **1.11.9**; documents `/apps/{name}/logs`.

## [1.11.8] - 2026-06-10

Server status via `cipi status` CLI (with host fallback).

### Changed

- **`CipiServerStatusService`** — prefers `sudo cipi status` and parses CLI output into the same structured JSON as before; falls back to direct host reads as `www-data` when sudo is unavailable (same pattern as `CipiDatabaseListCliService`).
- **`CipiOutputParser`** — adds `status` parser for `cipi status` output (system, resources, services, PHP pools, app count).
- **OpenAPI** — `info.version` bumped to **1.11.8**; `/status` documents the CLI-first path and sudo requirement.

### Fixed

- **`GET /api/status` / `ServerStatus` MCP tool** — no longer crash on `open_basedir` when counting PHP pools; CLI path avoids the restriction entirely, and the host fallback skips `is_dir()` outside the allowlist.

## [1.11.7] - 2026-06-10

Canonical token ability list for `cipi api token create`.

### Added

- **`config/cipi.php` → `token_abilities`** — canonical ability list for REST routes (includes `status-view`, `apps-suspend`, `apps-basicauth`, and all other route abilities).
- **`php artisan cipi:token-abilities`** — prints `ability|description` lines; `cipi api token create` reads this list (requires matching Cipi CLI update in `lib/api.sh`).

### Changed

- **OpenAPI** — `info.version` bumped to **1.11.7**.

## [1.11.6] - 2026-06-10

Structured server status over REST and MCP.

### Added

- **`GET /api/status`** — synchronous snapshot matching `cipi status` (System, Resources, Services, PHP, Apps count) as structured JSON. Requires `status-view` token ability. Reads host metrics as `www-data` (no sudo).
- **`status-view` token ability** — gates the status endpoint.
- **`CipiServerStatusService`** — collects the same metrics as `cipi status` (`free`, `df`, `top`, `systemctl`, PHP pools, apps.json count).

### Changed

- **`ServerStatus` MCP tool** — returns the same structured JSON as `GET /api/status` (still requires only `mcp-access`).
- **OpenAPI** — `info.version` bumped to **1.11.6**; documents `/status`.

## [1.11.5] - 2026-06-10

Safer MCP responses when exposing production logs or command output to AI clients.

### Added

- **`McpProductionContent`** — server-side redaction for common secrets (`password`, `Bearer`, `DB_PASSWORD`, SSH/DB credentials in CLI output, etc.) and a high-risk pattern detector (API tokens, payment-card-like numbers, DB connection strings) that prepends a targeted alert only when a match is found.

### Changed

- **MCP log tools** (`AppLogs`, `ApiLogShow`) — every response is prefixed with a mandatory production-content warning (_You are about to send production logs to the model. They may include personal data or secrets._); log text is redacted before delivery.
- **MCP sensitive output** (`JobShow` CLI `output`, `AppArtisan`) — redaction and conditional high-risk alert; structured job `result` (e.g. app-create credentials) is left intact.
- **Log defaults** — `CipiLogReader::DEFAULT_LINES` lowered from 100 to **50** (max remains 1000).
- **OpenAPI** — `info.version` bumped to **1.11.5**.

## [1.11.4] - 2026-06-10

Graceful MCP errors when required tool arguments are missing.

### Fixed

- **MCP argument validation** — new `McpArgValidator` applied across MCP tools; missing required parameters return `Error: … is required` instead of `TypeError` in `production.ERROR` (e.g. probe calls to `JobShow`, `AliasAdd`, `AppLogs`).

### Changed

- **OpenAPI** — `info.version` bumped to **1.11.4**.

## [1.11.3] - 2026-06-10

Fix MCP clients (e.g. Cursor) only discovering the first 15 tools.

### Fixed

- **`CipiServer`** — raises `defaultPaginationLength` to 50 so `tools/list` returns all registered tools in one page. Laravel MCP defaults to 15; clients that do not paginate `nextCursor` were missing `AliasAdd`, database tools, `JobShow`, `AppLogs`, etc. `tools/call` still worked for omitted tools.

### Changed

- **OpenAPI** — `info.version` bumped to **1.11.3**.

## [1.11.2] - 2026-06-10

Drop the unused `logs-view` token ability from the documented ability set.

### Removed

- **`logs-view` token ability** — never used on REST routes; `ApiLogShow` is available with `mcp-access` only (same as all other MCP tools).

### Changed

- **OpenAPI** — `info.version` bumped to **1.11.2**.

## [1.11.1] - 2026-06-10

Simplified MCP authorization: one ability for all tools.

### Changed

- **MCP authorization** — all MCP tools are gated only by the `mcp-access` token ability. Per-tool REST abilities (`apps-view`, `deploy-manage`, etc.) are no longer checked on `/mcp`.
- **OpenAPI** — `info.version` bumped to **1.11.1**; MCP description updated accordingly.

## [1.11.0] - 2026-06-10

MCP job polling, log reading, Artisan, and server monitoring tools.

### Added

- **MCP tools**
  - `JobShow` — poll background job status, parsed `result`, and CLI `output` (same data as `GET /api/jobs/{id}`).
  - `AppLogs` — read recent app log snapshots by type (`all`, `nginx`, `php`, `worker`, `deploy`, `laravel`), matching [`cipi app logs`](https://cipi.sh/docs/apps#cli-app-logs). Requires `apps-view`.
  - `ApiLogShow` — read recent Laravel logs for the Cipi API host app (`storage/logs/`).
  - `AppArtisan` — run Artisan on a Laravel app synchronously (same as [`cipi app artisan`](https://cipi.sh/docs/apps#cli-app-artisan)). Custom apps and `tinker` are rejected.
  - `ServerStatus` — server snapshot via `cipi status` (CPU, RAM, disk, services). Gated only by `mcp-access`.
  - `ServiceList` — system service status via `cipi service list [service]`. Gated only by `mcp-access`.
- **Services** — `CipiJobStatusService`, `CipiLogReader`, `CipiAppLogsService`, `CipiApiLogService`, `CipiAppArtisanService`, and `CipiServerMonitorService`.

### Changed

- Async MCP tools now suggest polling via `JobShow` instead of the REST endpoint.
- `JobController` delegates formatting to `CipiJobStatusService` (output truncated at 50k chars).
- **`CipiCliService`** — `app artisan`, `status`, and `service list` added to `ALLOWED_COMMANDS`.
- **`CipiValidationService`** — adds `isCustomApp(name)` helper reading the `custom` flag from `apps.json`.
- **OpenAPI** — `info.version` bumped to **1.11.0**; MCP description mentions job, log, Artisan, and server status tools.

## [1.10.0] - 2026-06-09

HTTP Basic Auth management for apps, wiring the REST API and MCP server to Cipi `cipi basicauth` commands (Nginx gatekeeper — unrelated to `cipi auth` / `auth.json`).

### Added

- **Basic auth endpoints**
  - `GET /api/apps/{name}/basicauth` — returns `{ enabled, users }` synchronously via `cipi basicauth status`. Requires `apps-basicauth`.
  - `POST /api/apps/{name}/basicauth/enable` — enables HTTP Basic Auth with optional `user` and `password` (auto-generated when omitted; returned once in the response). Runs `cipi basicauth enable` synchronously. Requires `apps-basicauth`.
  - `POST /api/apps/{name}/basicauth/disable` — removes basic auth and restores the normal vhost. Returns **409** when not enabled. Runs `cipi basicauth disable` synchronously. Requires `apps-basicauth`.
- **`basic_auth` flag** — `GET /api/apps` now exposes a boolean `basic_auth` field per app (from `apps.json`).
- **MCP tools** — `AppBasicAuthStatus`, `AppBasicAuthEnable`, and `AppBasicAuthDisable`, secured with `apps-basicauth`.
- **Token ability** — new `apps-basicauth` ability gates the REST routes and MCP tools.
- **OpenAPI** — `info.version` bumped to **1.10.0**; new paths and `BasicAuth*` schemas; `basic_auth` on app list schema.

### Changed

- **`CipiCliService`** — `basicauth enable`, `basicauth disable`, and `basicauth status` added to `ALLOWED_COMMANDS`.
- **`CipiValidationService`** — adds `isBasicAuthEnabled(name)` helper reading the `basic_auth` flag from `apps.json`.

## [1.9.0] - 2026-06-09

Primary domain change via app edit, aligned with [Cipi 4.6.2](https://github.com/cipi-sh/cipi/releases/tag/4.6.2) (`cipi app edit <app> --domain=<new>`).

### Added

- **`domain` on app edit** — `PUT /api/apps/{name}` accepts an optional `domain` field to rename the app's primary domain. Validates format synchronously and returns **409** if the domain is already used by another app (aliases of the current app are allowed, so promoting an alias to primary works). Dispatches `cipi app edit --domain=` as an `app-edit` async job. Composable with existing `php`, `branch`, and `repository` fields.
- **MCP `AppEdit` tool** — adds optional `domain` parameter with the same validation.
- **OpenAPI** — `info.version` bumped to **1.9.0**; `AppEditRequest.domain` schema, updated endpoint description, **409** response on edit, and `domain` in the `JobResultAppEdit` example.

## [1.8.1] - 2026-06-02

App suspend / unsuspend support, wiring the REST API and MCP server to the Cipi 4.5.8 lifecycle commands so apps can be taken offline (HTTP 503) without being deleted.

### Added

- **Suspend / unsuspend endpoints**
  - `POST /api/apps/{name}/suspend` — replaces the app's Nginx vhost with a generic static suspension page served as **HTTP 503** (HTTPS included). Validates the app exists synchronously and returns **409** if it is already suspended. Dispatches an `app-suspend` async job. Requires the new `apps-suspend` ability.
  - `POST /api/apps/{name}/unsuspend` — restores the normal vhost to bring the app back online. Validates the app exists synchronously and returns **409** if it is not currently suspended. Dispatches an `app-unsuspend` async job. Requires `apps-suspend`.
  - Both wrap the Cipi `cipi app suspend <app>` / `cipi app unsuspend <app>` commands from [Cipi 4.5.8](https://github.com/cipi-sh/cipi/releases/tag/4.5.8); suspension state lives in `apps.json` (`suspended`) and survives vhost regeneration.
- **`suspended` flag** — `GET /api/apps` and `GET /api/apps/{name}` now expose a boolean `suspended` field per app.
- **MCP tools** — `AppSuspend` and `AppUnsuspend`, secured with the `apps-suspend` ability and mirroring the REST validation.
- **Token ability** — new `apps-suspend` ability gates both the REST routes and the MCP tools.
- **Job result parsing** — `CipiOutputParser` parses `app-suspend` / `app-unsuspend` CLI output into structured `{ app, suspended }` results.
- **OpenAPI** — `info.version` bumped to **1.8.1**; new paths and `JobResultAppSuspend` / `JobResultAppUnsuspend` schemas; `app-suspend` / `app-unsuspend` added to the job-type enum; `suspended` added to the app list/show schemas.

### Changed

- **`CipiCliService`** — `app suspend` and `app unsuspend` added to the `ALLOWED_COMMANDS` whitelist.
- **`CipiValidationService`** — adds `isSuspended(name)` helper reading the `suspended` flag from `apps.json`.

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

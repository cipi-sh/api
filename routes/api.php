<?php

use CipiApi\Http\Controllers\AliasController;
use CipiApi\Http\Controllers\AppJitController;
use CipiApi\Http\Controllers\AppLimitsController;
use CipiApi\Http\Controllers\AppLogsController;
use CipiApi\Http\Controllers\AppController;
use CipiApi\Http\Controllers\AppRunController;
use CipiApi\Http\Controllers\ArtisanController;
use CipiApi\Http\Controllers\AuthJsonController;
use CipiApi\Http\Controllers\BackupController;
use CipiApi\Http\Controllers\BasicAuthController;
use CipiApi\Http\Controllers\DbController;
use CipiApi\Http\Controllers\DeployConfigController;
use CipiApi\Http\Controllers\DeployController;
use CipiApi\Http\Controllers\DiskController;
use CipiApi\Http\Controllers\EnvController;
use CipiApi\Http\Controllers\FirewallController;
use CipiApi\Http\Controllers\FrankenPhpController;
use CipiApi\Http\Controllers\JobController;
use CipiApi\Http\Controllers\HealthController;
use CipiApi\Http\Controllers\IpWhitelistController;
use CipiApi\Http\Controllers\MetricsController;
use CipiApi\Http\Controllers\NotificationController;
use CipiApi\Http\Controllers\MonitorController;
use CipiApi\Http\Controllers\NodeController;
use CipiApi\Http\Controllers\PackageController;
use CipiApi\Http\Controllers\PhpController;
use CipiApi\Http\Controllers\NginxController;
use CipiApi\Http\Controllers\PageCacheController;
use CipiApi\Http\Controllers\ProxyController;
use CipiApi\Http\Controllers\RedirectController;
use CipiApi\Http\Controllers\SearchController;
use CipiApi\Http\Controllers\ServiceController;
use CipiApi\Http\Controllers\SmtpController;
use CipiApi\Http\Controllers\SshController;
use CipiApi\Http\Controllers\SslController;
use CipiApi\Http\Controllers\ValkeyController;
use CipiApi\Http\Controllers\StatusController;
use CipiApi\Http\Controllers\WwwController;
use CipiApi\Http\Controllers\YmlController;
use CipiApi\Http\Controllers\ZtController;
use CipiApi\Http\Controllers\SyncController;
use CipiApi\Http\Controllers\ZtMeshController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware(['cipi.ip', 'auth:sanctum'])->group(function () {
    // Apps
    Route::get('/apps', [AppController::class, 'list'])->middleware('ability:apps-view');
    Route::get('/apps/{name}', [AppController::class, 'show'])->middleware('ability:apps-view');
    Route::get('/apps/{name}/logs', [AppLogsController::class, 'show'])->middleware('ability:apps-view');
    Route::post('/apps', [AppController::class, 'create'])->middleware('ability:apps-create');
    Route::put('/apps/{name}', [AppController::class, 'edit'])->middleware('ability:apps-edit');
    Route::post('/apps/{name}/webhook/recreate', [AppController::class, 'webhookRecreate'])->middleware('ability:apps-edit');
    Route::delete('/apps/{name}', [AppController::class, 'delete'])->middleware('ability:apps-delete');
    Route::post('/apps/{name}/suspend', [AppController::class, 'suspend'])->middleware('ability:apps-suspend');
    Route::post('/apps/{name}/unsuspend', [AppController::class, 'unsuspend'])->middleware('ability:apps-suspend');
    Route::post('/apps/{name}/fix-permissions', [AppController::class, 'fixPermissions'])->middleware('ability:apps-edit');
    Route::get('/apps/{name}/limits', [AppLimitsController::class, 'show'])->middleware('ability:apps-view');
    Route::put('/apps/{name}/limits', [AppLimitsController::class, 'update'])->middleware('ability:apps-edit');
    // PHP JIT per app (Cipi CLI ≥ 5.10.0): GET sync, PUT async job
    Route::get('/apps/{name}/jit', [AppJitController::class, 'show'])->middleware('ability:apps-view');
    Route::put('/apps/{name}/jit', [AppJitController::class, 'update'])->middleware('ability:apps-edit');
    Route::get('/apps/{name}/basicauth', [BasicAuthController::class, 'status'])->middleware('ability:apps-basicauth');
    Route::post('/apps/{name}/basicauth/enable', [BasicAuthController::class, 'enable'])->middleware('ability:apps-basicauth');
    Route::post('/apps/{name}/basicauth/disable', [BasicAuthController::class, 'disable'])->middleware('ability:apps-basicauth');
    // cipi/agent routes behind basic auth or not (Cipi CLI ≥ 5.11.0)
    Route::get('/apps/{name}/basicauth/agent', [BasicAuthController::class, 'agent'])->middleware('ability:apps-basicauth');
    Route::put('/apps/{name}/basicauth/agent', [BasicAuthController::class, 'setAgent'])->middleware('ability:apps-basicauth');

    // App .env (key/value — requires Cipi CLI ≥ 5.0.3)
    Route::get('/apps/{name}/env', [EnvController::class, 'show'])->middleware('ability:apps-env');
    Route::put('/apps/{name}/env', [EnvController::class, 'update'])->middleware('ability:apps-env');

    // Shared auth.json (Composer / structured JSON — not HTTP Basic Auth)
    Route::get('/apps/{name}/auth', [AuthJsonController::class, 'show'])->middleware('ability:apps-auth');
    Route::post('/apps/{name}/auth', [AuthJsonController::class, 'create'])->middleware('ability:apps-auth');
    Route::put('/apps/{name}/auth', [AuthJsonController::class, 'update'])->middleware('ability:apps-auth');
    Route::delete('/apps/{name}/auth', [AuthJsonController::class, 'delete'])->middleware('ability:apps-auth');

    // Artisan (async job)
    Route::post('/apps/{name}/artisan', [ArtisanController::class, 'run'])->middleware('ability:apps-artisan');

    // Whitelisted non-interactive app commands (async — requires Cipi CLI ≥ 5.0.3)
    Route::get('/run-commands', [AppRunController::class, 'commands'])->middleware('ability:apps-run');
    Route::post('/apps/{name}/run', [AppRunController::class, 'run'])->middleware('ability:apps-run');

    // Structured deploy.php options (sync — not raw PHP upload; requires Cipi CLI ≥ 5.0.3)
    Route::get('/apps/{name}/deploy-config', [DeployConfigController::class, 'show'])->middleware('ability:apps-deploy-config');
    Route::put('/apps/{name}/deploy-config', [DeployConfigController::class, 'update'])->middleware('ability:apps-deploy-config');

    // Aliases
    Route::get('/apps/{name}/aliases', [AliasController::class, 'list'])->middleware('ability:aliases-view');
    Route::post('/apps/{name}/aliases/{alias}', [AliasController::class, 'create'])->middleware('ability:aliases-create');
    Route::delete('/apps/{name}/aliases/{alias}', [AliasController::class, 'delete'])->middleware('ability:aliases-delete');

    // WWW / apex redirects (Cipi 4.8+)
    Route::get('/apps/{name}/www', [WwwController::class, 'status'])->middleware('ability:www-manage');
    Route::post('/apps/{name}/www/add', [WwwController::class, 'add'])->middleware('ability:www-manage');
    Route::post('/apps/{name}/www/force-to-root', [WwwController::class, 'forceToRoot'])->middleware('ability:www-manage');
    Route::post('/apps/{name}/www/force-from-root', [WwwController::class, 'forceFromRoot'])->middleware('ability:www-manage');
    Route::post('/apps/{name}/www/clear', [WwwController::class, 'clear'])->middleware('ability:www-manage');

    // App redirects and path redirects (Cipi CLI ≥ 5.3.1 + API sudoers ≥ 5.4.1)
    Route::get('/apps/{name}/redirects', [RedirectController::class, 'list'])->middleware('ability:redirects-view');
    Route::put('/apps/{name}/redirect', [RedirectController::class, 'set'])->middleware('ability:redirects-manage');
    Route::post('/apps/{name}/redirect/enable', [RedirectController::class, 'enable'])->middleware('ability:redirects-manage');
    Route::post('/apps/{name}/redirect/disable', [RedirectController::class, 'disable'])->middleware('ability:redirects-manage');
    Route::delete('/apps/{name}/redirect', [RedirectController::class, 'unset'])->middleware('ability:redirects-manage');
    Route::post('/apps/{name}/redirects', [RedirectController::class, 'add'])->middleware('ability:redirects-manage');
    Route::delete('/apps/{name}/redirects', [RedirectController::class, 'remove'])->middleware('ability:redirects-manage');

    // Prefix reverse proxies (Cipi CLI ≥ 5.3.1 + API sudoers ≥ 5.4.1)
    Route::get('/apps/{name}/proxies', [ProxyController::class, 'list'])->middleware('ability:proxies-view');
    Route::post('/apps/{name}/proxies', [ProxyController::class, 'add'])->middleware('ability:proxies-manage');
    Route::delete('/apps/{name}/proxies', [ProxyController::class, 'remove'])->middleware('ability:proxies-manage');

    // An app's own nginx directives (Cipi CLI ≥ 5.14.0). From the API the CLI
    // takes a subset of the language; a refused text is a 422 and changes nothing.
    Route::get('/apps/{name}/nginx', [NginxController::class, 'show'])->middleware('ability:nginx-view');
    Route::put('/apps/{name}/nginx', [NginxController::class, 'update'])->middleware('ability:nginx-manage');
    Route::delete('/apps/{name}/nginx', [NginxController::class, 'destroy'])->middleware('ability:nginx-manage');

    // The nginx page cache of an app (Cipi CLI ≥ 5.17.0): nginx answers with the page PHP
    // made, without running PHP. Synchronous; each change answers with the cache after it.
    Route::get('/cache', [PageCacheController::class, 'list'])->middleware('ability:nginx-view');
    Route::get('/apps/{name}/cache', [PageCacheController::class, 'show'])->middleware('ability:nginx-view');
    Route::put('/apps/{name}/cache', [PageCacheController::class, 'update'])->middleware('ability:nginx-manage');
    Route::put('/apps/{name}/cache/exclude', [PageCacheController::class, 'exclude'])->middleware('ability:nginx-manage');
    Route::post('/apps/{name}/cache/clear', [PageCacheController::class, 'clear'])->middleware('ability:nginx-manage');
    Route::post('/apps/{name}/cache/purge', [PageCacheController::class, 'purge'])->middleware('ability:nginx-manage');

    // Node apps + runtimes (Cipi CLI ≥ 5.4.0 + API sudoers ≥ 5.4.1)
    Route::get('/node', [NodeController::class, 'runtimes'])->middleware('ability:node-view');
    // Node runtimes — install, upgrade, server default, remove (Cipi CLI ≥ 5.9.0 sudoers; 503 on an older core)
    Route::post('/node/install', [NodeController::class, 'install'])->middleware('ability:node-runtimes-manage');
    Route::post('/node/upgrade', [NodeController::class, 'upgrade'])->middleware('ability:node-runtimes-manage');
    Route::put('/node/default', [NodeController::class, 'setDefault'])->middleware('ability:node-runtimes-manage');
    Route::delete('/node/{major}', [NodeController::class, 'remove'])->middleware('ability:node-runtimes-manage')
        ->where('major', '[0-9]{2}');
    Route::get('/apps/{name}/node', [NodeController::class, 'status'])->middleware('ability:node-view');
    Route::post('/apps/{name}/node/restart', [NodeController::class, 'restart'])->middleware('ability:node-manage');

    // cipi.yml — plan, apply, auto-apply after deploy (Cipi CLI ≥ 5.6.4 + API sudoers ≥ 5.6.4)
    Route::get('/apps/{name}/yml', [YmlController::class, 'show'])->middleware('ability:yml-view');
    Route::post('/apps/{name}/yml/apply', [YmlController::class, 'apply'])->middleware('ability:yml-manage');
    Route::post('/apps/{name}/yml/auto/enable', [YmlController::class, 'autoEnable'])->middleware('ability:yml-manage');
    Route::post('/apps/{name}/yml/auto/disable', [YmlController::class, 'autoDisable'])->middleware('ability:yml-manage');

    // Deploy
    Route::post('/apps/{name}/deploy', [DeployController::class, 'deploy'])->middleware('ability:deploy-manage');
    Route::post('/apps/{name}/deploy/rollback', [DeployController::class, 'rollback'])->middleware('ability:deploy-manage');
    Route::post('/apps/{name}/deploy/unlock', [DeployController::class, 'unlock'])->middleware('ability:deploy-manage');
    Route::get('/apps/{name}/deploy/audit', [DeployController::class, 'audit'])->middleware('ability:deploy-manage');
    // Cipi CLI ≥ 5.8.4: is a deploy running now (whoever started it)? 503 on an older core.
    Route::get('/apps/{name}/deploy/status', [DeployController::class, 'status'])->middleware('ability:deploy-manage');
    // Cipi CLI ≥ 5.9.0: past deploys with their duration (rotated logs included), and every app's last one.
    Route::get('/apps/{name}/deploy/history', [DeployController::class, 'history'])->middleware('ability:apps-view,deploy-manage');
    Route::get('/deploys/last', [DeployController::class, 'last'])->middleware('ability:apps-view,deploy-manage');

    // SSL
    Route::post('/apps/{name}/ssl', [SslController::class, 'install'])->middleware('ability:ssl-manage');
    Route::post('/apps/{name}/ssl/force', [SslController::class, 'force'])->middleware('ability:ssl-manage');
    Route::get('/ssl/dns', [SslController::class, 'dnsList'])->middleware('ability:ssl-manage');
    Route::put('/ssl/dns', [SslController::class, 'dnsSet'])->middleware('ability:ssl-manage');
    Route::delete('/ssl/dns/{account}', [SslController::class, 'dnsRemove'])->middleware('ability:ssl-manage');

    // Databases
    Route::get('/dbs/engines', [DbController::class, 'engines'])->middleware('ability:dbs-view');
    Route::post('/dbs/engines/install', [DbController::class, 'installEngine'])->middleware('ability:dbs-manage');
    Route::get('/dbs', [DbController::class, 'list'])->middleware('ability:dbs-view');
    Route::post('/dbs', [DbController::class, 'create'])->middleware('ability:dbs-create');
    // Dump upload in chunks, then POST /dbs/{name}/restore with upload_id (Cipi CLI ≥ 5.9.0)
    Route::get('/dbs/uploads/limits', [DbController::class, 'uploadLimits'])->middleware('ability:dbs-manage');
    Route::post('/dbs/uploads', [DbController::class, 'startUpload'])->middleware('ability:dbs-manage');
    Route::post('/dbs/uploads/{id}/chunks', [DbController::class, 'uploadChunk'])->middleware('ability:dbs-manage')->whereUuid('id');
    Route::delete('/dbs/uploads/{id}', [DbController::class, 'cancelUpload'])->middleware('ability:dbs-manage')->whereUuid('id');
    // A database shown as an app's (Cipi CLI ≥ 5.9.0)
    Route::post('/dbs/{name}/link', [DbController::class, 'link'])->middleware('ability:dbs-manage');
    Route::delete('/dbs/{name}/link', [DbController::class, 'unlink'])->middleware('ability:dbs-manage');
    Route::post('/dbs/{name}/backup', [DbController::class, 'backup'])->middleware('ability:dbs-manage');
    Route::post('/dbs/{name}/restore', [DbController::class, 'restore'])->middleware('ability:dbs-manage');
    Route::post('/dbs/{name}/password', [DbController::class, 'password'])->middleware('ability:dbs-manage');

    // Backup profiles (Cipi CLI ≥ 5.9.0; 503 on an older core). Destinations are created on the server.
    Route::get('/backups', [BackupController::class, 'status'])->middleware('ability:backups-view');
    Route::get('/backups/runs', [BackupController::class, 'runs'])->middleware('ability:backups-view');
    Route::post('/backups/run', [BackupController::class, 'run'])->middleware('ability:backups-manage');
    Route::post('/backups/verify', [BackupController::class, 'verify'])->middleware('ability:backups-manage');
    Route::post('/backups/destinations/test', [BackupController::class, 'testDestinations'])->middleware('ability:backups-manage');
    Route::post('/backups/profiles', [BackupController::class, 'createProfile'])->middleware('ability:backups-manage');
    Route::put('/backups/profiles/{name}', [BackupController::class, 'updateProfile'])->middleware('ability:backups-manage');
    Route::delete('/backups/profiles/{name}', [BackupController::class, 'deleteProfile'])->middleware('ability:backups-manage');
    Route::post('/backups/profiles/{name}/enable', [BackupController::class, 'enableProfile'])->middleware('ability:backups-manage');
    Route::post('/backups/profiles/{name}/disable', [BackupController::class, 'disableProfile'])->middleware('ability:backups-manage');

    // PHP versions (Cipi CLI ≥ 5.0.6)
    Route::get('/php', [PhpController::class, 'list'])->middleware('ability:php-view');
    Route::post('/php/install', [PhpController::class, 'install'])->middleware('ability:php-manage');
    // FrankenPHP builds of Octane apps (Cipi CLI ≥ 5.10.0): GET sync, upgrade async job
    Route::get('/frankenphp', [FrankenPhpController::class, 'status'])->middleware('ability:php-view');
    Route::post('/frankenphp/upgrade', [FrankenPhpController::class, 'upgrade'])->middleware('ability:php-manage');

    // The PHP of an Octane app (Cipi CLI ≥ 5.17.0): GET sync; the PHP by its number and a restart are jobs
    Route::get('/apps/{name}/octane', [FrankenPhpController::class, 'appPhp'])->middleware('ability:php-view');
    Route::put('/apps/{name}/octane/php', [FrankenPhpController::class, 'switchPhp'])->middleware('ability:php-manage');
    Route::post('/apps/{name}/octane/restart', [FrankenPhpController::class, 'restart'])->middleware('ability:php-manage');

    // SSH keys — cipi user (Cipi CLI ≥ 5.0.6)
    Route::get('/ssh/keys', [SshController::class, 'list'])->middleware('ability:ssh-view');
    Route::post('/ssh/keys', [SshController::class, 'add'])->middleware('ability:ssh-manage');
    Route::delete('/ssh/keys/{n}', [SshController::class, 'remove'])->middleware('ability:ssh-manage')
        ->where('n', '[0-9]+');

    // System services (Cipi CLI ≥ 5.0.6)
    Route::get('/services', [ServiceController::class, 'list'])->middleware('ability:services-view');
    Route::post('/services/{name}/restart', [ServiceController::class, 'restart'])
        ->middleware('ability:services-manage')
        ->where('name', '[a-z0-9.-]+');

    // Valkey per app, for the whole server (Cipi CLI ≥ 5.17.0). Giving an app its own
    // instance and moving it back are jobs; no password is ever in an answer.
    Route::get('/valkey', [ValkeyController::class, 'list'])->middleware('ability:services-view');
    Route::post('/valkey/isolate', [ValkeyController::class, 'isolate'])->middleware('ability:services-manage');
    Route::post('/valkey/share', [ValkeyController::class, 'share'])->middleware('ability:services-manage');
    Route::post('/valkey/flush-shared', [ValkeyController::class, 'flushShared'])->middleware('ability:services-manage');
    Route::post('/valkey/{name}/restart', [ValkeyController::class, 'restart'])->middleware('ability:services-manage');

    // SMTP notifications (Cipi CLI ≥ 5.0.7)
    Route::get('/smtp', [SmtpController::class, 'show'])->middleware('ability:smtp-view');
    Route::put('/smtp', [SmtpController::class, 'update'])->middleware('ability:smtp-manage');
    Route::post('/smtp/enable', [SmtpController::class, 'enable'])->middleware('ability:smtp-manage');
    Route::post('/smtp/disable', [SmtpController::class, 'disable'])->middleware('ability:smtp-manage');
    Route::post('/smtp/test', [SmtpController::class, 'test'])->middleware('ability:smtp-manage');
    Route::delete('/smtp', [SmtpController::class, 'destroy'])->middleware('ability:smtp-manage');

    // App HTTP healthchecks (Cipi CLI ≥ 5.0.7 for --json)
    Route::get('/health', [HealthController::class, 'list'])->middleware('ability:health-view');
    Route::get('/apps/{name}/health', [HealthController::class, 'show'])->middleware('ability:health-view');
    Route::put('/apps/{name}/health', [HealthController::class, 'update'])->middleware('ability:health-manage');
    Route::delete('/apps/{name}/health', [HealthController::class, 'destroy'])->middleware('ability:health-manage');
    Route::post('/apps/{name}/health/check', [HealthController::class, 'check'])->middleware('ability:health-view');

    // Meilisearch / Laravel Scout (Cipi CLI ≥ 5.2.2)
    Route::get('/search', [SearchController::class, 'status'])->middleware('ability:search-view');
    Route::post('/apps/{name}/search/enable', [SearchController::class, 'enable'])->middleware('ability:search-manage');
    Route::post('/apps/{name}/search/disable', [SearchController::class, 'disable'])->middleware('ability:search-manage');

    // Optional host packages: the catalog (Cipi CLI ≥ 5.2.2); install and
    // remove an entry as jobs (Cipi CLI ≥ 5.14.0, closed catalog)
    Route::get('/packages', [PackageController::class, 'list'])->middleware('ability:packages-view');
    Route::post('/packages/{package}', [PackageController::class, 'install'])->middleware('ability:packages-manage');
    Route::delete('/packages/{package}', [PackageController::class, 'remove'])->middleware('ability:packages-manage');

    // System monitor checks — read-only (Cipi CLI ≥ 5.3.0)
    Route::get('/monitor', [MonitorController::class, 'list'])->middleware('ability:monitor-view');

    // Notifications: every channel and trigger (Cipi CLI ≥ 5.12.0). Tokens of the SMTP page (smtp-*) keep working.
    Route::get('/notifications', [NotificationController::class, 'show'])->middleware('ability:notifications-view,smtp-view');
    Route::post('/notifications/channels', [NotificationController::class, 'store'])->middleware('ability:notifications-manage,smtp-manage');
    Route::put('/notifications/channels/{id}', [NotificationController::class, 'update'])->middleware('ability:notifications-manage,smtp-manage');
    Route::delete('/notifications/channels/{id}', [NotificationController::class, 'destroy'])->middleware('ability:notifications-manage,smtp-manage');
    Route::post('/notifications/channels/{id}/enable', [NotificationController::class, 'enable'])->middleware('ability:notifications-manage,smtp-manage');
    Route::post('/notifications/channels/{id}/disable', [NotificationController::class, 'disable'])->middleware('ability:notifications-manage,smtp-manage');
    Route::post('/notifications/channels/{id}/test', [NotificationController::class, 'test'])->middleware('ability:notifications-manage,smtp-manage');
    Route::get('/notifications/triggers', [NotificationController::class, 'triggers'])->middleware('ability:notifications-view,smtp-view');
    Route::put('/notifications/triggers', [NotificationController::class, 'updateTriggers'])->middleware('ability:notifications-manage,smtp-manage');
    Route::post('/notifications/triggers/reset', [NotificationController::class, 'resetTriggers'])->middleware('ability:notifications-manage,smtp-manage');
    // How notifications read (Cipi CLI ≥ 5.17.0): the time zone of their Time line, the name they give the server
    Route::get('/notifications/settings', [NotificationController::class, 'settings'])->middleware('ability:notifications-view,smtp-view');
    Route::put('/notifications/settings', [NotificationController::class, 'updateSettings'])->middleware('ability:notifications-manage,smtp-manage');

    // Metrics & health history (Cipi CLI ≥ 5.11.0) — synchronous reads of the server's SQLite
    Route::get('/metrics', [MetricsController::class, 'overview'])->middleware('ability:metrics-view');
    Route::get('/metrics/series', [MetricsController::class, 'series'])->middleware('ability:metrics-view');
    Route::get('/metrics/uptime', [MetricsController::class, 'uptime'])->middleware('ability:metrics-view');
    Route::get('/metrics/events', [MetricsController::class, 'events'])->middleware('ability:metrics-view');
    Route::get('/metrics/settings', [MetricsController::class, 'settings'])->middleware('ability:metrics-view');
    Route::put('/metrics/settings', [MetricsController::class, 'updateSettings'])->middleware('ability:metrics-manage');
    Route::put('/metrics/heartbeat', [MetricsController::class, 'setHeartbeat'])->middleware('ability:metrics-manage');
    Route::delete('/metrics/heartbeat', [MetricsController::class, 'deleteHeartbeat'])->middleware('ability:metrics-manage');
    Route::post('/metrics/heartbeat/test', [MetricsController::class, 'testHeartbeat'])->middleware('ability:metrics-manage');
    Route::get('/apps/{name}/metrics', [MetricsController::class, 'appSeries'])->middleware('ability:metrics-view');

    // Firewall: UFW, fail2ban, CrowdSec (Cipi CLI ≥ 5.14.0; 503 on an older core). Synchronous,
    // each change answers with the firewall after it; CrowdSec on/off are jobs.
    Route::get('/firewall', [FirewallController::class, 'status'])->middleware('ability:firewall-view');
    Route::post('/firewall/rules', [FirewallController::class, 'allow'])->middleware('ability:firewall-manage');
    Route::delete('/firewall/rules', [FirewallController::class, 'remove'])->middleware('ability:firewall-manage');
    Route::post('/firewall/blocks', [FirewallController::class, 'block'])->middleware('ability:firewall-manage');
    Route::delete('/firewall/blocks', [FirewallController::class, 'unblock'])->middleware('ability:firewall-manage');
    Route::put('/firewall/attempts', [FirewallController::class, 'attempts'])->middleware('ability:firewall-manage');
    Route::delete('/firewall/bans', [FirewallController::class, 'unban'])->middleware('ability:firewall-manage');
    Route::post('/firewall/crowdsec/enable', [FirewallController::class, 'crowdsecEnable'])->middleware('ability:firewall-manage');
    Route::post('/firewall/crowdsec/disable', [FirewallController::class, 'crowdsecDisable'])->middleware('ability:firewall-manage');
    Route::post('/firewall/crowdsec/allowlist', [FirewallController::class, 'crowdsecAllow'])->middleware('ability:firewall-manage');
    Route::delete('/firewall/crowdsec/allowlist', [FirewallController::class, 'crowdsecUnallow'])->middleware('ability:firewall-manage');
    // The visitor's address behind Cloudflare (Cipi CLI ≥ 5.15.0): `real_ip` in GET /firewall.
    Route::put('/firewall/real-ip', [FirewallController::class, 'realIpEnable'])->middleware('ability:firewall-manage');
    Route::post('/firewall/real-ip/refresh', [FirewallController::class, 'realIpRefresh'])->middleware('ability:firewall-manage');
    Route::delete('/firewall/real-ip', [FirewallController::class, 'realIpDisable'])->middleware('ability:firewall-manage');

    // Zero Trust — Cloudflare's side is read-only (Cipi CLI ≥ 5.3.0); the mesh
    // network (Tailscale, NetBird; Cipi CLI ≥ 5.18.0) is joined, left and locked
    // from here too. Synchronous: a join carries a key.
    Route::get('/zt', [ZtController::class, 'status'])->middleware('ability:zt-view');
    Route::post('/zt/mesh', [ZtMeshController::class, 'join'])->middleware('ability:zt-manage');
    Route::delete('/zt/mesh', [ZtMeshController::class, 'leave'])->middleware('ability:zt-manage');
    Route::post('/zt/mesh/lock', [ZtMeshController::class, 'lock'])->middleware('ability:zt-manage');
    Route::delete('/zt/mesh/lock', [ZtMeshController::class, 'unlock'])->middleware('ability:zt-manage');
    Route::put('/zt/mesh/ssh', [ZtMeshController::class, 'ssh'])->middleware('ability:zt-manage');

    // Moving apps to another server (cipi sync, Cipi ≥ 5.18.0). As strong as ssh-manage.
    Route::get('/sync/key', [SyncController::class, 'key'])->middleware('ability:sync-manage');
    Route::post('/sync/check', [SyncController::class, 'check'])->middleware('ability:sync-manage');
    Route::post('/sync/trust', [SyncController::class, 'trust'])->middleware('ability:sync-manage');
    Route::delete('/sync/trust', [SyncController::class, 'untrust'])->middleware('ability:sync-manage');
    Route::post('/sync/push', [SyncController::class, 'push'])->middleware('ability:sync-manage');

    // Disk usage — the server, every app, every database (Cipi CLI ≥ 5.5.0 + API sudoers ≥ 5.5.2)
    Route::get('/disk', [DiskController::class, 'usage'])->middleware('ability:disk-view');
    Route::get('/disk/dbs', [DiskController::class, 'databases'])->middleware('ability:disk-view');

    // Jobs
    Route::get('/jobs/{id}', [JobController::class, 'show']);

    // Server
    Route::get('/status', [StatusController::class, 'show'])->middleware('ability:status-view');

    // API client IP whitelist (Cipi CLI ≥ 5.0.8) — default * = allow all
    Route::get('/ip-whitelist', [IpWhitelistController::class, 'show'])->middleware('ability:ip-whitelist-view');
    Route::put('/ip-whitelist', [IpWhitelistController::class, 'update'])->middleware('ability:ip-whitelist-manage');
    Route::post('/ip-whitelist', [IpWhitelistController::class, 'add'])->middleware('ability:ip-whitelist-manage');
    Route::delete('/ip-whitelist', [IpWhitelistController::class, 'remove'])->middleware('ability:ip-whitelist-manage');
    Route::post('/ip-whitelist/allow-all', [IpWhitelistController::class, 'allowAll'])->middleware('ability:ip-whitelist-manage');
});

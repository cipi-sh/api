<?php

namespace CipiApi\Mcp\Servers;

use CipiApi\Mcp\Tools\AliasAddTool;
use CipiApi\Mcp\Tools\AliasListTool;
use CipiApi\Mcp\Tools\AliasRemoveTool;
use CipiApi\Mcp\Tools\AppCreateTool;
use CipiApi\Mcp\Tools\AppDeleteTool;
use CipiApi\Mcp\Tools\AppFixPermissionsTool;
use CipiApi\Mcp\Tools\DeployAuditTool;
use CipiApi\Mcp\Tools\MonitorListTool;
use CipiApi\Mcp\Tools\NodeRestartTool;
use CipiApi\Mcp\Tools\NodeRuntimesTool;
use CipiApi\Mcp\Tools\NodeStatusTool;
use CipiApi\Mcp\Tools\PackageListTool;
use CipiApi\Mcp\Tools\ProxyAddTool;
use CipiApi\Mcp\Tools\ProxyListTool;
use CipiApi\Mcp\Tools\ProxyRemoveTool;
use CipiApi\Mcp\Tools\RedirectAddTool;
use CipiApi\Mcp\Tools\RedirectListTool;
use CipiApi\Mcp\Tools\RedirectRemoveTool;
use CipiApi\Mcp\Tools\RedirectSetTool;
use CipiApi\Mcp\Tools\RedirectToggleTool;
use CipiApi\Mcp\Tools\RedirectUnsetTool;
use CipiApi\Mcp\Tools\SearchDisableTool;
use CipiApi\Mcp\Tools\SearchEnableTool;
use CipiApi\Mcp\Tools\SearchStatusTool;
use CipiApi\Mcp\Tools\ZtStatusTool;
use CipiApi\Mcp\Tools\AppDeployConfigShowTool;
use CipiApi\Mcp\Tools\AppDeployConfigUpdateTool;
use CipiApi\Mcp\Tools\AppDeployRollbackTool;
use CipiApi\Mcp\Tools\AppDeployTool;
use CipiApi\Mcp\Tools\AppDeployUnlockTool;
use CipiApi\Mcp\Tools\AppEditTool;
use CipiApi\Mcp\Tools\AppListTool;
use CipiApi\Mcp\Tools\AppShowTool;
use CipiApi\Mcp\Tools\ApiLogShowTool;
use CipiApi\Mcp\Tools\AppArtisanTool;
use CipiApi\Mcp\Tools\AppAuthJsonCreateTool;
use CipiApi\Mcp\Tools\AppAuthJsonDeleteTool;
use CipiApi\Mcp\Tools\AppAuthJsonShowTool;
use CipiApi\Mcp\Tools\AppAuthJsonUpdateTool;
use CipiApi\Mcp\Tools\AppBasicAuthDisableTool;
use CipiApi\Mcp\Tools\AppBasicAuthEnableTool;
use CipiApi\Mcp\Tools\AppBasicAuthStatusTool;
use CipiApi\Mcp\Tools\AppEnvShowTool;
use CipiApi\Mcp\Tools\AppEnvUpdateTool;
use CipiApi\Mcp\Tools\AppLogsTool;
use CipiApi\Mcp\Tools\AppRunCommandsTool;
use CipiApi\Mcp\Tools\AppRunTool;
use CipiApi\Mcp\Tools\AppSuspendTool;
use CipiApi\Mcp\Tools\AppUnsuspendTool;
use CipiApi\Mcp\Tools\AppWebhookRecreateTool;
use CipiApi\Mcp\Tools\DbBackupTool;
use CipiApi\Mcp\Tools\DbCreateTool;
use CipiApi\Mcp\Tools\DbEnginesTool;
use CipiApi\Mcp\Tools\DbListTool;
use CipiApi\Mcp\Tools\DbPasswordTool;
use CipiApi\Mcp\Tools\DbRestoreTool;
use CipiApi\Mcp\Tools\IpWhitelistShowTool;
use CipiApi\Mcp\Tools\JobShowTool;
use CipiApi\Mcp\Tools\PhpListTool;
use CipiApi\Mcp\Tools\ServerStatusTool;
use CipiApi\Mcp\Tools\ServiceListTool;
use CipiApi\Mcp\Tools\SslForceTool;
use CipiApi\Mcp\Tools\SslInstallTool;
use CipiApi\Mcp\Tools\WwwAddTool;
use CipiApi\Mcp\Tools\WwwClearTool;
use CipiApi\Mcp\Tools\WwwForceFromRootTool;
use CipiApi\Mcp\Tools\WwwForceToRootTool;
use CipiApi\Mcp\Tools\WwwStatusTool;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server;

#[Name('Cipi Server')]
#[Version('1.1.0')]
#[Instructions('Cipi server management: apps (Laravel, custom, Node), aliases, www redirects, app/path redirects, prefix proxies, databases (MariaDB/PostgreSQL), SSL, Meilisearch, deploy audit, monitor, jobs, logs, and server status. Requires mcp-access token ability only.')]
class CipiServer extends Server
{
    /**
     * Laravel MCP paginates tools/list (default 15). Cursor does not fetch further pages,
     * so expose all Cipi tools in a single list response.
     */
    public int $defaultPaginationLength = 80;

    public int $maxPaginationLength = 80;

    protected array $tools = [
        AppListTool::class,
        AppShowTool::class,
        AppArtisanTool::class,
        AppRunTool::class,
        AppRunCommandsTool::class,
        AppDeployConfigShowTool::class,
        AppDeployConfigUpdateTool::class,
        AppEnvShowTool::class,
        AppEnvUpdateTool::class,
        AppAuthJsonShowTool::class,
        AppAuthJsonCreateTool::class,
        AppAuthJsonUpdateTool::class,
        AppAuthJsonDeleteTool::class,
        AppCreateTool::class,
        AppEditTool::class,
        AppWebhookRecreateTool::class,
        AppDeleteTool::class,
        AppDeployTool::class,
        AppDeployRollbackTool::class,
        AppDeployUnlockTool::class,
        AppSuspendTool::class,
        AppUnsuspendTool::class,
        AppFixPermissionsTool::class,
        AppBasicAuthStatusTool::class,
        AppBasicAuthEnableTool::class,
        AppBasicAuthDisableTool::class,
        AliasListTool::class,
        AliasAddTool::class,
        AliasRemoveTool::class,
        WwwStatusTool::class,
        WwwAddTool::class,
        WwwForceToRootTool::class,
        WwwForceFromRootTool::class,
        WwwClearTool::class,
        RedirectListTool::class,
        RedirectSetTool::class,
        RedirectToggleTool::class,
        RedirectUnsetTool::class,
        RedirectAddTool::class,
        RedirectRemoveTool::class,
        ProxyListTool::class,
        ProxyAddTool::class,
        ProxyRemoveTool::class,
        NodeRuntimesTool::class,
        NodeStatusTool::class,
        NodeRestartTool::class,
        DeployAuditTool::class,
        SearchStatusTool::class,
        SearchEnableTool::class,
        SearchDisableTool::class,
        PackageListTool::class,
        MonitorListTool::class,
        ZtStatusTool::class,
        DbEnginesTool::class,
        DbListTool::class,
        DbCreateTool::class,
        DbBackupTool::class,
        DbRestoreTool::class,
        DbPasswordTool::class,
        SslInstallTool::class,
        SslForceTool::class,
        JobShowTool::class,
        AppLogsTool::class,
        ApiLogShowTool::class,
        ServerStatusTool::class,
        ServiceListTool::class,
        PhpListTool::class,
        IpWhitelistShowTool::class,
    ];
}

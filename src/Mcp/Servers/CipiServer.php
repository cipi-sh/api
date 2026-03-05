<?php

namespace CipiApi\Mcp\Servers;

use CipiApi\Mcp\Tools\AliasAddTool;
use CipiApi\Mcp\Tools\AliasListTool;
use CipiApi\Mcp\Tools\AliasRemoveTool;
use CipiApi\Mcp\Tools\AppCreateTool;
use CipiApi\Mcp\Tools\AppDeleteTool;
use CipiApi\Mcp\Tools\AppDeployRollbackTool;
use CipiApi\Mcp\Tools\AppDeployTool;
use CipiApi\Mcp\Tools\AppDeployUnlockTool;
use CipiApi\Mcp\Tools\AppEditTool;
use CipiApi\Mcp\Tools\AppListTool;
use CipiApi\Mcp\Tools\AppShowTool;
use CipiApi\Mcp\Tools\SslInstallTool;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server;

#[Name('Cipi Server')]
#[Version('1.0.0')]
#[Instructions('Cipi server management: apps, aliases, SSL. Uses same token as REST API.')]
class CipiServer extends Server
{
    protected array $tools = [
        AppListTool::class,
        AppShowTool::class,
        AppCreateTool::class,
        AppEditTool::class,
        AppDeleteTool::class,
        AppDeployTool::class,
        AppDeployRollbackTool::class,
        AppDeployUnlockTool::class,
        AliasListTool::class,
        AliasAddTool::class,
        AliasRemoveTool::class,
        SslInstallTool::class,
    ];
}

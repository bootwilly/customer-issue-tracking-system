<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetIssueTool;
use App\Mcp\Tools\ListIssuesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTicketsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('CITS')]
#[Version('1.0.0')]
#[Instructions('客戶問題追蹤系統（CITS）唯讀查詢。所有結果僅限權杖使用者有權限的專案。')]
class CitsServer extends Server
{
    protected array $tools = [
        ListProjectsTool::class,
        ListIssuesTool::class,
        GetIssueTool::class,
        ListTicketsTool::class,
    ];
}

<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_projects')]
#[Description('列出目前權杖使用者可存取的專案（代碼與名稱）。唯讀。')]
class ListProjectsTool extends McpTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, fn ($svc, $user) => $svc->listProjects($user));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

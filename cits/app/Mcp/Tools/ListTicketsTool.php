<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_tickets')]
#[Description('列出客戶單（唯讀）。可依狀態、關鍵字篩選。')]
class ListTicketsTool extends McpTool
{
    public function handle(Request $request): Response
    {
        $a = $request->validate([
            'project' => ['nullable', 'string', 'max:30'], 'status' => ['nullable', 'string', 'max:30'],
            'keyword' => ['nullable', 'string', 'max:100'], 'limit' => ['nullable', 'integer'],
        ]);

        return $this->run($request, fn ($svc, $user) => $svc->listTickets($user, $a));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('專案代碼（可省略）'),
            'status' => $schema->string()->description('狀態代碼'),
            'keyword' => $schema->string()->description('標題或單號關鍵字'),
            'limit' => $schema->integer()->description('筆數，預設 50，最大 200'),
        ];
    }
}

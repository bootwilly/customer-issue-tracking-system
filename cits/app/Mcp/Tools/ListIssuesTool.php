<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_issues')]
#[Description('列出內部問題單（唯讀）。可依狀態、階段、負責人、關鍵字篩選；預設只列未結束的單。')]
class ListIssuesTool extends McpTool
{
    public function handle(Request $request): Response
    {
        $a = $request->validate([
            'project' => ['nullable', 'string', 'max:30'], 'status' => ['nullable', 'string', 'max:30'],
            'stage' => ['nullable', 'string', 'max:10'], 'assignee' => ['nullable', 'string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:100'], 'open_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer'],
        ]);

        return $this->run($request, fn ($svc, $user) => $svc->listIssues($user, $a));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('專案代碼（可省略；省略時查所有可存取專案）'),
            'status' => $schema->string()->description('狀態代碼'),
            'stage' => $schema->string()->description('階段（PM / DEV / TEST / RELEASE）'),
            'assignee' => $schema->string()->description('負責人帳號（開發或測試）'),
            'keyword' => $schema->string()->description('標題或單號關鍵字'),
            'open_only' => $schema->boolean()->description('只列未結束的單，預設 true'),
            'limit' => $schema->integer()->description('筆數，預設 50，最大 200'),
        ];
    }
}

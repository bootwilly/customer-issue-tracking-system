<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_issue')]
#[Description('依單號取得單一問題單：內容純文字、關聯客戶單、最近 20 筆事件（唯讀）。')]
class GetIssueTool extends McpTool
{
    public function handle(Request $request): Response
    {
        $a = $request->validate(['issue_no' => ['required', 'string', 'max:30']]);

        return $this->run($request, fn ($svc, $user) => $svc->getIssue($user, $a['issue_no']));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['issue_no' => $schema->string()->description('問題單號')->required()];
    }
}

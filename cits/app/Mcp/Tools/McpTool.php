<?php

namespace App\Mcp\Tools;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Mcp\McpQueryService;
use Closure;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** Base for read-only CITS tools: resolves the token user and turns business errors into tool errors. */
abstract class McpTool extends Tool
{
    protected function run(Request $request, Closure $fn): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('未授權。');
        }
        try {
            return Response::json($fn(app(McpQueryService::class), $user));
        } catch (BusinessException $e) {
            return Response::error($e->getMessage());
        }
    }
}

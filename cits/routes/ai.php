<?php

use App\Http\Middleware\McpTokenAuth;
use App\Mcp\Servers\CitsServer;
use Laravel\Mcp\Facades\Mcp;

// Stateless bearer-token endpoint: not in the 'web' group, so no session / CSRF.
Mcp::web('/mcp', CitsServer::class)->middleware(McpTokenAuth::class);

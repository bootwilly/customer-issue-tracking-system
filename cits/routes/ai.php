<?php

use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\McpTokenAuth;
use App\Mcp\Servers\CitsServer;
use Laravel\Mcp\Facades\Mcp;

// Stateless bearer-token endpoint: not in the 'web' group, so no session / CSRF.
// ForceHttps first: a bearer token must never travel over plain http (no-op outside production)
Mcp::web('/mcp', CitsServer::class)->middleware([ForceHttps::class, McpTokenAuth::class]);

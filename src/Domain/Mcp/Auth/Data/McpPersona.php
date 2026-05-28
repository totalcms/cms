<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Data;

/**
 * Identifies the caller of an MCP request.
 *
 * Used to filter the tool surface so admin-only tools never appear in
 * tools/list for an unauthenticated caller. AUTHENTICATED is produced
 * by McpAuth when a valid OAuth Bearer token with at least one `mcp:*`
 * scope is presented; OAuthScopeEvaluator further gates the specific
 * MCP method (tools/call vs resources/read) by token scope.
 */
enum McpPersona: string
{
	case ADMIN         = 'admin';
	case PUBLIC_       = 'public';
	case AUTHENTICATED = 'authenticated';
}

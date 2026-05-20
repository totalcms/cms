<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Data;

/**
 * Identifies the caller of an MCP request.
 *
 * Used to filter the tool surface so admin-only tools never appear in tools/list
 * for an unauthenticated caller. AUTHENTICATED is reserved for Phase 4 (OAuth
 * scoped tokens); it currently has no path to be produced by McpAuth.
 */
enum McpPersona: string
{
	case ADMIN         = 'admin';
	case PUBLIC_       = 'public';
	case AUTHENTICATED = 'authenticated';
}

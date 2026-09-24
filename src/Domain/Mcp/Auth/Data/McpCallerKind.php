<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Data;

/**
 * How an MCP caller proved who it is. Persona says what it may see; kind
 * says how it arrived, which is what the read-only rule keys on: a browser
 * session (WebMCP) reads only, however much the user could do in the admin.
 */
enum McpCallerKind: string
{
	case ApiKey    = 'api-key';
	case OAuth     = 'oauth';
	case Session   = 'session';
	case Anonymous = 'anonymous';
}

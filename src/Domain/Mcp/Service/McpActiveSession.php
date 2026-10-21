<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

/**
 * Request-scoped record of the MCP session the current /mcp request belongs
 * to, written by McpEndpointAction from the `Mcp-Session-Id` header.
 *
 * McpSessionInvalidator reads it to spare that one session when a tool call
 * changes the tool surface (create_schema, a collection's `mcp.access`): the
 * request is still running, and from mcp/sdk 0.9 the SDK refuses to save a
 * session that was deleted under it, so the call that made the change failed
 * with an internal error. The client that made the change already knows about
 * it; every other session is still dropped.
 */
class McpActiveSession
{
	private string $id = '';

	public function set(string $id): void
	{
		$this->id = trim($id);
	}

	public function id(): string
	{
		return $this->id;
	}
}

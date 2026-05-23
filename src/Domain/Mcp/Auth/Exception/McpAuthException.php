<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Exception;

/**
 * Thrown by McpAuth when an MCP request lacks valid credentials.
 *
 * The endpoint action converts this to a 401 JSON response with a
 * WWW-Authenticate header keyed off `$reason`:
 *   - `login_required` — no credentials were supplied and publicAccess is off.
 *   - `invalid_token`  — credentials were supplied but didn't validate (bad
 *     key, expired key, or key without MCP scope).
 *
 * The distinction matters for lazy-auth UX in MCP clients: hosts react
 * differently to "you need to log in" vs. "the credentials you sent are
 * wrong."
 */
class McpAuthException extends \RuntimeException
{
	public function __construct(
		string $message,
		public readonly string $reason = 'invalid_token',
	) {
		parent::__construct($message);
	}
}

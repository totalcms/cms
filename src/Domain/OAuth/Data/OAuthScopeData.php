<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Data;

/**
 * Value object describing a single OAuth scope from the registry.
 *
 * `impliedPaths` is a list of regex patterns matched against
 * "{HTTP method} {path}" for REST API access (e.g. `^GET /api/collections/`).
 * `mcpOperations` is a list of MCP operation identifiers
 * (e.g. `tool:query_collection`, `resource:read`).
 * `implies` is a list of other scope identifiers that this scope grants
 * automatically (e.g. cms:admin implies cms:read + cms:write).
 */
readonly class OAuthScopeData
{
	/**
	 * @param list<string> $impliedPaths
	 * @param list<string> $mcpOperations
	 * @param list<string> $implies
	 */
	public function __construct(
		public string $identifier,
		public string $description,
		public array $impliedPaths,
		public array $mcpOperations,
		public array $implies,
	) {
	}
}

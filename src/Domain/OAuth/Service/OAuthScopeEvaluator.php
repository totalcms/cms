<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

/**
 * Given a token's scopes + a requested operation, returns whether
 * access is granted. Used by McpEndpointAction to gate AUTHENTICATED-
 * persona calls on top of the persona-based tool/resource visibility
 * filter.
 *
 * Operation strings come from the JSON-RPC method on incoming MCP
 * requests (e.g., "tools/call", "resources/read", "tools/list",
 * "resources/subscribe"). The evaluator walks the requesting token's
 * scopes (expanded via OAuthScopeRegistry to include implied scopes)
 * and asks whether any scope's mcpOperations array contains the
 * requested operation.
 *
 * REST path evaluation uses isRestPathAllowed(), which matches the
 * "{METHOD} {path}" string against each scope's impliedPaths regex
 * patterns (e.g., `#^GET\s+/api/(collections|objects)#`).
 */
final readonly class OAuthScopeEvaluator
{
	public function __construct(
		private OAuthScopeRegistry $registry,
	) {
	}

	/**
	 * Check whether the token scopes permit an MCP operation.
	 *
	 * @param list<string> $tokenScopes
	 */
	public function isAllowed(array $tokenScopes, string $operation): bool
	{
		$expanded = $this->registry->expand($tokenScopes);
		foreach ($expanded as $scopeId) {
			if (!$this->registry->has($scopeId)) {
				continue;
			}
			$scope = $this->registry->get($scopeId);
			foreach ($scope->mcpOperations as $allowed) {
				if ($operation === $allowed) {
					return true;
				}
				// Prefix match for "tools/call:tool_name" style — a scope
				// allowing "tools/call" implicitly allows any specific
				// tool invocation within it.
				if (str_starts_with($operation, $allowed . ':')) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check whether the token scopes permit a REST API request.
	 *
	 * The operation string format is "{METHOD} {path}", e.g. "GET /api/collections/blog".
	 * Each scope's impliedPaths is a list of regex patterns matched against this string.
	 * Scopes are expanded transitively before evaluation (cms:admin implies cms:read + cms:write).
	 *
	 * @param list<string> $tokenScopes
	 */
	public function isRestPathAllowed(array $tokenScopes, string $operation): bool
	{
		$expanded = $this->registry->expand($tokenScopes);
		foreach ($expanded as $scopeId) {
			if (!$this->registry->has($scopeId)) {
				continue;
			}
			$scope = $this->registry->get($scopeId);
			foreach ($scope->impliedPaths as $pattern) {
				if (preg_match($pattern, $operation) === 1) {
					return true;
				}
			}
		}

		return false;
	}
}

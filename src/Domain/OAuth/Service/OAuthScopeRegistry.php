<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

use TotalCMS\Domain\OAuth\Data\OAuthScopeData;

/**
 * Single source of truth for the OAuth scope vocabulary.
 *
 * Five coarse scopes — adding fine-grained scopes (per-tool, per-collection)
 * would be additive here. Consent screens render `description` verbatim;
 * `impliedPaths` drives REST gating; `mcpOperations` drives MCP gating;
 * `implies` lets higher-level scopes grant lower-level ones.
 */
final class OAuthScopeRegistry
{
	/**
	 * Scopes only an admin-group user can convey into a token. Requested by a
	 * non-admin, they are narrowed away at consent display and finalizeScopes()
	 * alike — REST trusts token scopes as authority (BaseAccessMiddleware skips
	 * group checks for Bearer callers), so issuing cms:admin to a non-admin
	 * would hand them the admin REST surface their groups deny.
	 *
	 * @var list<string>
	 */
	public const ADMIN_GATED = ['cms:admin'];

	/** @var array<string,OAuthScopeData>|null */
	private ?array $scopes = null;

	/**
	 * @return list<OAuthScopeData>
	 */
	public function all(): array
	{
		return array_values($this->scopes());
	}

	public function get(string $identifier): OAuthScopeData
	{
		$scopes = $this->scopes();
		if (!isset($scopes[$identifier])) {
			throw new \OutOfBoundsException("Unknown OAuth scope: {$identifier}");
		}

		return $scopes[$identifier];
	}

	public function has(string $identifier): bool
	{
		return isset($this->scopes()[$identifier]);
	}

	/**
	 * Expand a list of scope identifiers to include all implied scopes
	 * (transitive closure). cms:admin → [cms:admin, cms:read, cms:write].
	 *
	 * @param  list<string> $identifiers
	 *
	 * @return list<string>
	 */
	public function expand(array $identifiers): array
	{
		$expanded = [];
		$queue    = $identifiers;
		while ($queue !== []) {
			$id = array_shift($queue);
			if (in_array($id, $expanded, true) || !$this->has($id)) {
				continue;
			}
			$expanded[] = $id;
			foreach ($this->get($id)->implies as $implied) {
				$queue[] = $implied;
			}
		}

		return $expanded;
	}

	/**
	 * @return array<string,OAuthScopeData>
	 */
	private function scopes(): array
	{
		if ($this->scopes !== null) {
			return $this->scopes;
		}

		$defs = [
			new OAuthScopeData(
				identifier: 'cms:read',
				description: 'Read your content',
				impliedPaths: ['#^GET\s+/api/(collections|objects)#'],
				mcpOperations: ['tool:query_collection', 'tool:get_object', 'tool:search_collection', 'tool:list_collections'],
				implies: [],
			),
			new OAuthScopeData(
				identifier: 'cms:write',
				description: 'Create, update, and delete your content',
				impliedPaths: ['#^(POST|PUT|PATCH|DELETE)\s+/api/(collections|objects)#'],
				mcpOperations: [],
				implies: [],
			),
			new OAuthScopeData(
				identifier: 'cms:admin',
				description: 'Administer your site (implies read + write)',
				impliedPaths: ['#^[A-Z]+\s+/api/(schemas|cache|ext)#'],
				mcpOperations: ['tool:schema_create', 'tool:schema_update', 'tool:schema_delete', 'tool:clear_cache', 'tool:extension_list'],
				implies: ['cms:read', 'cms:write'],
			),
			new OAuthScopeData(
				identifier: 'mcp:tools',
				description: 'Call AI tools on your site',
				impliedPaths: [],
				mcpOperations: ['initialize', 'tools/call', 'tools/list'],
				implies: [],
			),
			new OAuthScopeData(
				identifier: 'mcp:resources',
				description: 'Read addressable AI resources',
				impliedPaths: [],
				mcpOperations: ['initialize', 'resources/read', 'resources/subscribe', 'resources/list', 'resources/templates/list'],
				implies: [],
			),
			new OAuthScopeData(
				identifier: 'mcp:prompts',
				description: 'List and retrieve AI prompts',
				impliedPaths: [],
				mcpOperations: ['initialize', 'prompts/list', 'prompts/get'],
				implies: [],
			),
		];

		$this->scopes = [];
		foreach ($defs as $scope) {
			$this->scopes[$scope->identifier] = $scope;
		}

		return $this->scopes;
	}
}

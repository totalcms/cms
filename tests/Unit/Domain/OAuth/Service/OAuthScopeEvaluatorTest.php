<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Service\OAuthScopeEvaluator;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;

final class OAuthScopeEvaluatorTest extends TestCase
{
	private OAuthScopeEvaluator $evaluator;

	protected function setUp(): void
	{
		$this->evaluator = new OAuthScopeEvaluator(new OAuthScopeRegistry());
	}

	// ── mcp:tools ──────────────────────────────────────────────────────────

	public function testMcpToolsAllowsToolsCall(): void
	{
		$this->assertTrue($this->evaluator->isAllowed(['mcp:tools'], 'tools/call'));
	}

	public function testMcpToolsAllowsToolsList(): void
	{
		$this->assertTrue($this->evaluator->isAllowed(['mcp:tools'], 'tools/list'));
	}

	public function testMcpToolsAllowsPrefixedToolsCall(): void
	{
		// Prefix match: "tools/call" scope covers "tools/call:query_collection"
		$this->assertTrue($this->evaluator->isAllowed(['mcp:tools'], 'tools/call:query_collection'));
	}

	public function testMcpToolsDoesNotAllowResourcesRead(): void
	{
		$this->assertFalse($this->evaluator->isAllowed(['mcp:tools'], 'resources/read'));
	}

	// ── mcp:resources ──────────────────────────────────────────────────────

	public function testMcpResourcesAllowsResourcesRead(): void
	{
		$this->assertTrue($this->evaluator->isAllowed(['mcp:resources'], 'resources/read'));
	}

	public function testMcpResourcesAllowsResourcesSubscribe(): void
	{
		$this->assertTrue($this->evaluator->isAllowed(['mcp:resources'], 'resources/subscribe'));
	}

	public function testMcpResourcesAllowsResourcesList(): void
	{
		$this->assertTrue($this->evaluator->isAllowed(['mcp:resources'], 'resources/list'));
	}

	public function testMcpResourcesAllowsResourcesTemplatesList(): void
	{
		$this->assertTrue($this->evaluator->isAllowed(['mcp:resources'], 'resources/templates/list'));
	}

	public function testMcpResourcesDoesNotAllowToolsCall(): void
	{
		$this->assertFalse($this->evaluator->isAllowed(['mcp:resources'], 'tools/call'));
	}

	// ── cms scopes ─────────────────────────────────────────────────────────

	public function testCmsReadDoesNotAllowMcpProtocolOperations(): void
	{
		// cms:read covers tool:* operations in its mcpOperations, but NOT the
		// JSON-RPC protocol methods tools/call or resources/read.
		$this->assertFalse($this->evaluator->isAllowed(['cms:read'], 'tools/call'));
		$this->assertFalse($this->evaluator->isAllowed(['cms:read'], 'resources/read'));
		$this->assertFalse($this->evaluator->isAllowed(['cms:read'], 'tools/list'));
	}

	public function testCmsReadAllowsToolQueryCollection(): void
	{
		// cms:read does carry tool:query_collection in its mcpOperations — verify
		// the evaluator resolves it (even though the gate in McpEndpointAction
		// passes the JSON-RPC method, extension tooling may use this path).
		$this->assertTrue($this->evaluator->isAllowed(['cms:read'], 'tool:query_collection'));
	}

	public function testCmsWriteAllowsNothingInMcpNamespace(): void
	{
		// cms:write has empty mcpOperations in v1.
		$this->assertFalse($this->evaluator->isAllowed(['cms:write'], 'tools/call'));
		$this->assertFalse($this->evaluator->isAllowed(['cms:write'], 'resources/read'));
		$this->assertFalse($this->evaluator->isAllowed(['cms:write'], 'tool:query_collection'));
	}

	// ── edge cases ─────────────────────────────────────────────────────────

	public function testEmptyTokenScopesAlwaysFalse(): void
	{
		$this->assertFalse($this->evaluator->isAllowed([], 'tools/call'));
		$this->assertFalse($this->evaluator->isAllowed([], 'resources/read'));
	}

	public function testUnknownScopeInTokenIsIgnoredAndDoesNotCrash(): void
	{
		// expand() skips unknown scopes; the evaluator must not throw.
		$this->assertFalse($this->evaluator->isAllowed(['foo:bar'], 'tools/call'));
	}

	public function testUnknownScopeAlongsideValidScopeStillWorks(): void
	{
		$this->assertTrue($this->evaluator->isAllowed(['foo:bar', 'mcp:tools'], 'tools/call'));
	}

	// ── transitive expansion ────────────────────────────────────────────────

	public function testCmsAdminTransitivelyImpliesCmsRead(): void
	{
		// cms:admin implies cms:read, which has tool:query_collection in
		// mcpOperations — so a cms:admin-only token gets that operation.
		$this->assertTrue($this->evaluator->isAllowed(['cms:admin'], 'tool:query_collection'));
	}

	public function testCmsAdminTransitivelyImpliesCmsWrite(): void
	{
		// cms:write has empty mcpOperations, so this should still be false.
		$this->assertFalse($this->evaluator->isAllowed(['cms:admin'], 'tools/call'));
	}

	public function testCmsAdminOwnMcpOperationsAreGranted(): void
	{
		// cms:admin directly lists tool:schema_create etc.
		$this->assertTrue($this->evaluator->isAllowed(['cms:admin'], 'tool:schema_create'));
		$this->assertTrue($this->evaluator->isAllowed(['cms:admin'], 'tool:clear_cache'));
	}

	// ── combined scopes ─────────────────────────────────────────────────────

	public function testCombinedMcpToolsAndMcpResourcesAllowsBoth(): void
	{
		$scopes = ['mcp:tools', 'mcp:resources'];
		$this->assertTrue($this->evaluator->isAllowed($scopes, 'tools/call'));
		$this->assertTrue($this->evaluator->isAllowed($scopes, 'resources/read'));
	}

	public function testPrefixMatchDoesNotOvermatch(): void
	{
		// "tools/call" prefix match should NOT grant access to "tools/callx" —
		// prefix match only fires when the colon separator is present.
		$this->assertFalse($this->evaluator->isAllowed(['mcp:tools'], 'tools/callx'));
	}
}

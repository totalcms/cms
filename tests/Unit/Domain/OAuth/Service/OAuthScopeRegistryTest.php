<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;

final class OAuthScopeRegistryTest extends TestCase
{
	public function testCoarseScopesPresent(): void
	{
		$registry = new OAuthScopeRegistry();
		$identifiers = array_map(fn ($s) => $s->identifier, $registry->all());

		$this->assertEqualsCanonicalizing(
			['cms:read', 'cms:write', 'cms:admin', 'mcp:tools', 'mcp:resources', 'mcp:prompts'],
			$identifiers,
		);
	}

	public function testCmsAdminImpliesReadAndWrite(): void
	{
		$registry = new OAuthScopeRegistry();
		$expanded = $registry->expand(['cms:admin']);

		$this->assertContains('cms:admin', $expanded);
		$this->assertContains('cms:read', $expanded);
		$this->assertContains('cms:write', $expanded);
	}

	public function testExpandIsIdempotent(): void
	{
		$registry = new OAuthScopeRegistry();
		$once  = $registry->expand(['cms:admin']);
		$twice = $registry->expand($once);

		$this->assertEqualsCanonicalizing($once, $twice);
	}

	public function testUnknownScopeThrows(): void
	{
		$registry = new OAuthScopeRegistry();

		$this->expectException(\OutOfBoundsException::class);
		$registry->get('cms:bogus');
	}

	public function testRequiresFiveScopesByDefaultAreCustomerFacing(): void
	{
		$registry = new OAuthScopeRegistry();
		foreach ($registry->all() as $scope) {
			$this->assertNotSame('', $scope->description, "scope {$scope->identifier} has empty description");
		}
	}
}

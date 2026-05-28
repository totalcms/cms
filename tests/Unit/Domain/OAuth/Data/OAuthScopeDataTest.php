<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Data\OAuthScopeData;

final class OAuthScopeDataTest extends TestCase
{
	public function testConstructsWithImpliedPathsAndOperations(): void
	{
		$scope = new OAuthScopeData(
			identifier: 'cms:read',
			description: 'Read your content',
			impliedPaths: ['^GET /api/collections/'],
			mcpOperations: ['tool:query_collection'],
			implies: [],
		);

		$this->assertSame('cms:read', $scope->identifier);
		$this->assertContains('tool:query_collection', $scope->mcpOperations);
	}
}

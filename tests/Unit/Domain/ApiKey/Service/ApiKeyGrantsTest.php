<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ApiKey\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyGrants;
use TotalCMS\Domain\Auth\Data\UserAuthority;

final class ApiKeyGrantsTest extends TestCase
{
	/**
	 * @param list<string> $paths
	 */
	private function key(array $paths, array $methods = ['GET']): ApiKeyData
	{
		return new ApiKeyData([
			'id'      => 'k1',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2026-01-01T00:00:00Z',
			'scopes'  => ['methods' => $methods, 'paths' => $paths],
		]);
	}

	private function authority(array $paths): UserAuthority
	{
		$authority = ApiKeyGrants::authority($this->key($paths));
		$this->assertInstanceOf(UserAuthority::class, $authority);

		return $authority;
	}

	public function testAStarKeyIsUnlimited(): void
	{
		$this->assertTrue(ApiKeyGrants::isUnlimited($this->key(['*'])));
		$this->assertNull(ApiKeyGrants::authority($this->key(['/mcp', '*'])));
	}

	public function testANamedCollectionGrantsThatCollectionOnly(): void
	{
		$authority = $this->authority(['/mcp', '/api/collections/Blog/']);

		$this->assertTrue($authority->canCollection('read', 'blog'));
		$this->assertTrue($authority->canCollection('delete', 'blog'));
		$this->assertTrue($authority->canCollectionMeta('read', 'blog'));
		$this->assertFalse($authority->canCollection('read', 'auth'));
		$this->assertFalse($authority->canSchema('read', 'blog'));
	}

	public function testAnObjectPathGrantsItsCollection(): void
	{
		$this->assertTrue($this->authority(['/collections/blog/home'])->canCollection('read', 'blog'));
	}

	public function testTheBareSectionsGrantEverythingInThem(): void
	{
		$authority = $this->authority(['/collections', '/schemas', '/templates']);

		$this->assertTrue($authority->canCollection('update', 'anything'));
		$this->assertTrue($authority->canSchema('update', 'anything'));
		$this->assertTrue($authority->canBuilder());
		$this->assertFalse($authority->isAdmin);
	}

	public function testAnMcpOnlyKeyGrantsNoContent(): void
	{
		$authority = $this->authority(['/mcp']);

		$this->assertFalse($authority->canCollectionsOperation('read'));
		$this->assertFalse($authority->canSchemasOperation('read'));
		$this->assertFalse($authority->canBuilder());
		$this->assertFalse($authority->canAnyUtil());
	}

	public function testViewPathsGrantThoseViews(): void
	{
		$this->assertTrue(ApiKeyGrants::reachesView($this->key(['/api/dataviews/sales/data']), 'sales'));
		$this->assertTrue(ApiKeyGrants::reachesView($this->key(['/dataviews/sales']), 'sales'));
		$this->assertFalse(ApiKeyGrants::reachesView($this->key(['/dataviews/sales/data']), 'payroll'));
		$this->assertTrue(ApiKeyGrants::reachesView($this->key(['/dataviews']), 'payroll'));
		$this->assertTrue(ApiKeyGrants::reachesView($this->key(['*']), 'payroll'));
		$this->assertFalse(ApiKeyGrants::reachesView($this->key(['/mcp', '/collections']), 'sales'));
	}

	public function testMethodsDoNotNarrowTheGrant(): void
	{
		// Over MCP every call is a POST: the key's methods say nothing there.
		$authority = ApiKeyGrants::authority($this->key(['/collections/blog'], ['GET']));

		$this->assertTrue($authority?->canCollection('update', 'blog'));
	}
}

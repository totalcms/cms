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
	private function key(array $paths, array $methods = ['GET', 'POST', 'PUT', 'DELETE']): ApiKeyData
	{
		return new ApiKeyData([
			'id'      => 'k1',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2026-01-01T00:00:00Z',
			'scopes'  => ['methods' => $methods, 'paths' => $paths],
		]);
	}

	private function authority(array $paths, array $methods = ['GET', 'POST', 'PUT', 'DELETE']): UserAuthority
	{
		$authority = ApiKeyGrants::authority($this->key($paths, $methods));
		$this->assertInstanceOf(UserAuthority::class, $authority);

		return $authority;
	}

	public function testAStarKeyWithEveryOperationIsUnlimited(): void
	{
		$this->assertTrue(ApiKeyGrants::isUnlimited($this->key(['*'])));
		$this->assertTrue(ApiKeyGrants::isUnlimited($this->key(['*'], ['GET', 'POST', 'PATCH', 'DELETE'])));
		$this->assertNull(ApiKeyGrants::authority($this->key(['/mcp', '*'])));
	}

	public function testAStarKeyMissingAMethodIsLimitedToItsOperationsEverywhere(): void
	{
		$authority = $this->authority(['*'], ['GET']);

		$this->assertFalse(ApiKeyGrants::isUnlimited($this->key(['*'], ['GET'])));
		$this->assertTrue($authority->canCollection('read', 'anything'));
		$this->assertFalse($authority->canCollection('update', 'anything'));
		$this->assertTrue($authority->canSchema('read', 'anything'));
		$this->assertTrue($authority->canBuilder());
		$this->assertTrue(ApiKeyGrants::reachesView($this->key(['*'], ['GET']), 'sales'));
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
		$this->assertTrue($authority->canSchema('read', 'anything'));
		$this->assertTrue($authority->canBuilder());
		$this->assertFalse($authority->isAdmin);
	}

	public function testStructureIsReadOnlyWhateverTheMethods(): void
	{
		$authority = $this->authority(['/collections', '/schemas']);

		$this->assertTrue($authority->canCollectionMeta('read', 'blog'));
		foreach (['create', 'update', 'delete'] as $operation) {
			$this->assertFalse($authority->canCollectionMeta($operation, 'blog'), $operation);
			$this->assertFalse($authority->canSchema($operation, 'blog'), $operation);
		}
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

	public function testMethodsDecideTheOperationsAsOnRest(): void
	{
		$readOnly = $this->authority(['/collections/blog'], ['GET']);
		$this->assertTrue($readOnly->canCollection('read', 'blog'));
		$this->assertFalse($readOnly->canCollection('create', 'blog'));
		$this->assertFalse($readOnly->canCollection('update', 'blog'));

		$noDelete = $this->authority(['/collections/blog'], ['GET', 'POST', 'PATCH']);
		$this->assertTrue($noDelete->canCollection('create', 'blog'));
		$this->assertTrue($noDelete->canCollection('update', 'blog'));
		$this->assertFalse($noDelete->canCollection('delete', 'blog'));
	}

	public function testAViewNeedsGet(): void
	{
		$this->assertFalse(ApiKeyGrants::reachesView($this->key(['/dataviews'], ['POST']), 'sales'));
	}
}

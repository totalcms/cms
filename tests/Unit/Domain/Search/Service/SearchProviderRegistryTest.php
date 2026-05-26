<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Search\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;

final class SearchProviderRegistryTest extends TestCase
{
	public function testRegisterAndGet(): void
	{
		$registry = new SearchProviderRegistry();
		$provider = $this->makeProvider('algolia');

		$registry->register($provider);

		$this->assertSame($provider, $registry->get('algolia'));
	}

	public function testGetReturnsNullForUnknown(): void
	{
		$registry = new SearchProviderRegistry();

		$this->assertNull($registry->get('nope'));
	}

	public function testAllReturnsListInRegistrationOrder(): void
	{
		$registry = new SearchProviderRegistry();
		$registry->register($this->makeProvider('text'));
		$registry->register($this->makeProvider('algolia'));

		$all = $registry->all();
		$this->assertCount(2, $all);
		$this->assertSame('text', $all[0]->id());
		$this->assertSame('algolia', $all[1]->id());
	}

	public function testStrictCollisionDeny(): void
	{
		$registry = new SearchProviderRegistry();
		$registry->register($this->makeProvider('algolia'));

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/already registered.*algolia/i');

		$registry->register($this->makeProvider('algolia'));
	}

	public function testActiveReturnsRequestedProvider(): void
	{
		$registry = new SearchProviderRegistry();
		$text     = $this->makeProvider('text');
		$algolia  = $this->makeProvider('algolia');
		$registry->register($text);
		$registry->register($algolia);

		$this->assertSame($algolia, $registry->active('algolia'));
	}

	public function testActiveReturnsNullWhenIdMissing(): void
	{
		$registry = new SearchProviderRegistry();
		$registry->register($this->makeProvider('text'));

		$this->assertNull($registry->active('algolia'));
	}

	private function makeProvider(string $id): SearchProvider
	{
		return new class($id) implements SearchProvider {
			public function __construct(private readonly string $id) {}
			public function id(): string { return $this->id; }
			public function label(): string { return ucfirst($this->id); }
			public function search(SearchQuery $query): array { return []; }
			public function index(string $collection, string $id, array $data): void {}
			public function delete(string $collection, string $id): void {}
			public function isAvailable(): bool { return true; }
		};
	}
}

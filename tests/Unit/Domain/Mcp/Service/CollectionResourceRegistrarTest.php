<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Mcp\Resources\CollectionObjectResource;
use TotalCMS\Domain\Mcp\Resources\CollectionResource;
use TotalCMS\Domain\Mcp\Service\CollectionResourceRegistrar;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\ResourceRegistry;

final class CollectionResourceRegistrarTest extends TestCase
{
	// ── Helpers ──────────────────────────────────────────────────────────────

	/** @param array<string,mixed> $mcp */
	private function makeCollection(string $id, string $name, array $mcp = []): CollectionData
	{
		$collection       = new CollectionData();
		$collection->id   = $id;
		$collection->name = $name;
		$collection->schema = 'text';
		$collection->mcp  = $mcp;

		return $collection;
	}

	/**
	 * @param array<CollectionData> $collections
	 * @return array{
	 *     registrar: CollectionResourceRegistrar,
	 *     registry: ResourceRegistry,
	 *     repo: MockObject&CollectionRepository,
	 *     resolver: MockObject&McpSchemaResolver,
	 *     collectionResource: MockObject&CollectionResource,
	 *     objectResource: MockObject&CollectionObjectResource,
	 * }
	 */
	private function makeRegistrar(array $collections): array
	{
		/** @var MockObject&CollectionRepository $repo */
		$repo = $this->createMock(CollectionRepository::class);
		$repo->method('listAllCollections')->willReturn($collections);

		/** @var MockObject&McpSchemaResolver $resolver */
		$resolver = $this->createMock(McpSchemaResolver::class);

		/** @var MockObject&CollectionResource $collectionResource */
		$collectionResource = $this->createMock(CollectionResource::class);

		/** @var MockObject&CollectionObjectResource $objectResource */
		$objectResource = $this->createMock(CollectionObjectResource::class);

		$registry   = new ResourceRegistry();
		$registrar  = new CollectionResourceRegistrar(
			collectionRepository:    $repo,
			schemaResolver:          $resolver,
			collectionResource:      $collectionResource,
			collectionObjectResource: $objectResource,
		);

		return compact('registrar', 'registry', 'repo', 'resolver', 'collectionResource', 'objectResource');
	}

	// ── Tests ─────────────────────────────────────────────────────────────────

	public function testEmptyRepositoryRegistersNothing(): void
	{
		['registrar' => $registrar, 'registry' => $registry] = $this->makeRegistrar([]);

		$registrar->registerAll($registry);

		$this->assertCount(0, $registry->all());
		$this->assertCount(0, $registry->allTemplates());
	}

	public function testPublicCollectionRegistersOneResourceAndOneTemplate(): void
	{
		$collection = $this->makeCollection('blog', 'Blog', ['access' => 'public', 'resource' => true]);

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver] = $this->makeRegistrar([$collection]);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'public',
			'description' => null,
			'resource'    => true,
		]);

		$registrar->registerAll($registry);

		$this->assertCount(1, $registry->all());
		$this->assertCount(1, $registry->allTemplates());

		$resource = $registry->get('tcms://blog/');
		$this->assertNotNull($resource);
		$this->assertSame('public', $resource->access);

		$template = $registry->getTemplate('tcms://blog/{id}');
		$this->assertNotNull($template);
		$this->assertSame('public', $template->access);
	}

	public function testResourceFalseSkipsCollection(): void
	{
		$collection = $this->makeCollection('private', 'Private', ['resource' => false]);

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver] = $this->makeRegistrar([$collection]);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'admin',
			'description' => null,
			'resource'    => false,
		]);

		$registrar->registerAll($registry);

		$this->assertCount(0, $registry->all());
		$this->assertCount(0, $registry->allTemplates());
	}

	public function testAdminCollectionRegistersWithAdminAccess(): void
	{
		$collection = $this->makeCollection('members', 'Members', ['access' => 'admin', 'resource' => true]);

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver] = $this->makeRegistrar([$collection]);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'admin',
			'description' => null,
			'resource'    => true,
		]);

		$registrar->registerAll($registry);

		$resource = $registry->get('tcms://members/');
		$this->assertNotNull($resource);
		$this->assertSame('admin', $resource->access);

		$template = $registry->getTemplate('tcms://members/{id}');
		$this->assertNotNull($template);
		$this->assertSame('admin', $template->access);
	}

	public function testAuthenticatedAccessIsNormalizedToAdmin(): void
	{
		$collection = $this->makeCollection('gated', 'Gated', ['access' => 'authenticated', 'resource' => true]);

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver] = $this->makeRegistrar([$collection]);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'authenticated',
			'description' => null,
			'resource'    => true,
		]);

		$registrar->registerAll($registry);

		$resource = $registry->get('tcms://gated/');
		$this->assertNotNull($resource);
		$this->assertSame('admin', $resource->access);

		$template = $registry->getTemplate('tcms://gated/{id}');
		$this->assertNotNull($template);
		$this->assertSame('admin', $template->access);
	}

	public function testDescriptionFallbackWhenNoneProvided(): void
	{
		$collection = $this->makeCollection('news', 'News');

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver] = $this->makeRegistrar([$collection]);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'public',
			'description' => null,
			'resource'    => true,
		]);

		$registrar->registerAll($registry);

		$resource = $registry->get('tcms://news/');
		$this->assertNotNull($resource);
		$this->assertSame('News collection — recent items.', $resource->description);
	}

	public function testExplicitDescriptionIsUsed(): void
	{
		$collection = $this->makeCollection('news', 'News', ['description' => 'Custom desc']);

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver] = $this->makeRegistrar([$collection]);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'public',
			'description' => 'Custom desc',
			'resource'    => true,
		]);

		$registrar->registerAll($registry);

		$resource = $registry->get('tcms://news/');
		$this->assertNotNull($resource);
		$this->assertSame('Custom desc', $resource->description);
	}

	public function testMultipleCollectionsAllRegistered(): void
	{
		$collections = [
			$this->makeCollection('blog', 'Blog'),
			$this->makeCollection('news', 'News'),
			$this->makeCollection('events', 'Events'),
		];

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver] = $this->makeRegistrar($collections);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'public',
			'description' => null,
			'resource'    => true,
		]);

		$registrar->registerAll($registry);

		$this->assertCount(3, $registry->all());
		$this->assertCount(3, $registry->allTemplates());

		$this->assertNotNull($registry->get('tcms://blog/'));
		$this->assertNotNull($registry->get('tcms://news/'));
		$this->assertNotNull($registry->get('tcms://events/'));

		$this->assertNotNull($registry->getTemplate('tcms://blog/{id}'));
		$this->assertNotNull($registry->getTemplate('tcms://news/{id}'));
		$this->assertNotNull($registry->getTemplate('tcms://events/{id}'));
	}

	public function testResourceHandlerDelegatesToCollectionResource(): void
	{
		$collection = $this->makeCollection('blog', 'Blog');

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver, 'collectionResource' => $collectionResource] = $this->makeRegistrar([$collection]);

		$sentinel = ['contents' => [['uri' => 'tcms://blog/', 'mimeType' => 'application/json', 'text' => '{}' ]]];

		$resolver->method('forCollection')->willReturn([
			'access'      => 'public',
			'description' => null,
			'resource'    => true,
		]);

		$collectionResource->expects($this->once())
			->method('read')
			->with('blog')
			->willReturn($sentinel);

		$registrar->registerAll($registry);

		$resource = $registry->get('tcms://blog/');
		$this->assertNotNull($resource);

		$result = ($resource->handler)();
		$this->assertSame($sentinel, $result);
	}

	public function testTemplateHandlerDelegatesToCollectionObjectResource(): void
	{
		$collection = $this->makeCollection('blog', 'Blog');

		['registrar' => $registrar, 'registry' => $registry, 'resolver' => $resolver, 'objectResource' => $objectResource] = $this->makeRegistrar([$collection]);

		$sentinel = ['contents' => [['uri' => 'tcms://blog/post-1', 'mimeType' => 'application/json', 'text' => '{}' ]]];

		$resolver->method('forCollection')->willReturn([
			'access'      => 'public',
			'description' => null,
			'resource'    => true,
		]);

		$objectResource->expects($this->once())
			->method('read')
			->with('blog', 'post-1')
			->willReturn($sentinel);

		$registrar->registerAll($registry);

		$template = $registry->getTemplate('tcms://blog/{id}');
		$this->assertNotNull($template);

		$result = ($template->handler)('post-1');
		$this->assertSame($sentinel, $result);
	}

	// ── SDK-safe name derivation (A7) ────────────────────────────────────────

	public function testResourceAndTemplateNamesUseCollectionIdNotDisplayName(): void
	{
		// SDK validates resource/template `name` against /^[a-zA-Z0-9_-]+$/.
		// Display names may contain spaces and em-dashes; the registrar must
		// derive names from the collection id (already-validated as safe).
		[
			'registrar' => $registrar,
			'registry'  => $registry,
			'resolver'  => $resolver,
		] = $this->makeRegistrar([
			$this->makeCollection('blog', 'My Blog — Posts', ['access' => 'public']),
		]);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'public',
			'description' => null,
			'resource'    => true,
		]);

		$registrar->registerAll($registry);

		$resource = $registry->get('tcms://blog/');
		$this->assertNotNull($resource);
		$this->assertSame('blog', $resource->name, 'resource name must be SDK-safe (collection id), not display name');

		$template = $registry->getTemplate('tcms://blog/{id}');
		$this->assertNotNull($template);
		$this->assertSame('blog-item', $template->name, 'template name must be {id}-item to distinguish it from the collection resource');
	}

	public function testCollectionIdWithHyphensIsAccepted(): void
	{
		// Collection ids like `builder-page` are valid per SchemaSaver and
		// already match the SDK regex; toSdkName should pass them through.
		[
			'registrar' => $registrar,
			'registry'  => $registry,
			'resolver'  => $resolver,
		] = $this->makeRegistrar([
			$this->makeCollection('builder-page', 'Builder pages', ['access' => 'public']),
		]);

		$resolver->method('forCollection')->willReturn([
			'access'      => 'public',
			'description' => null,
			'resource'    => true,
		]);

		$registrar->registerAll($registry);

		$resource = $registry->get('tcms://builder-page/');
		$this->assertNotNull($resource);
		$this->assertSame('builder-page', $resource->name);

		$template = $registry->getTemplate('tcms://builder-page/{id}');
		$this->assertNotNull($template);
		$this->assertSame('builder-page-item', $template->name);
	}
}

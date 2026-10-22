<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Resource\Handler;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Resource\Handler\CollectionResource;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpDescriptionResolver;
use TotalCMS\Domain\Mcp\Service\McpObjectShaper;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class CollectionResourceTest extends TestCase
{
	private MockObject $collectionFetcher;
	private MockObject $indexReader;
	private MockObject $schemaResolver;
	private MockObject $urlBuilder;
	private PersonaContext $personaContext;

	private ActorContext $actors;
	private CollectionResource $resource;

	protected function setUp(): void
	{
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->indexReader       = $this->createMock(IndexReader::class);
		$this->schemaResolver    = $this->createMock(McpSchemaResolver::class);
		$this->urlBuilder        = $this->createMock(ObjectUrlBuilder::class);
		// PersonaContext (Task 10b) needs its own CollectionFetcher +
		// McpSchemaResolver to resolve the mcp.access:'public' carve-out via
		// canReadCollection(). Reuses this test's existing mocks rather than
		// creating separate ones — CollectionResource always passes the
		// already-fetched CollectionData into canReadCollection() (see
		// CollectionResource::read()), so PersonaContext's own
		// $collectionFetcher is never actually invoked from this call path;
		// it's supplied only to satisfy the constructor. schemaResolver's
		// forCollection() IS invoked (see below) to resolve the collection's
		// mcp.access.
		$this->actors         = new ActorContext();
		$this->personaContext = new PersonaContext($this->collectionFetcher, $this->schemaResolver, mcpGuard($this), $this->actors);
		// Derives 'access' from whatever mcp.access the test's CollectionData
		// carries, so canReadCollection()'s public-collection carve-out
		// reflects each test's actual fixture instead of a hardcoded default.
		$this->schemaResolver->method('forCollection')->willReturnCallback(
			static fn (CollectionData $c): array => [
				'access'        => (string)($c->mcp['access'] ?? 'admin'),
				'owned'         => false,
				'description'   => null,
				'resource'      => true,
				'titleProperty' => '',
			],
		);

		$this->resource = new CollectionResource(
			$this->collectionFetcher,
			$this->indexReader,
			$this->schemaResolver,
			new McpObjectShaper($this->schemaResolver, $this->createStub(ContentRenderer::class), $this->urlBuilder),
			$this->personaContext,
		);
	}

	private function collection(string $id = 'blog', string $access = 'public'): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = $id;
		$collection->schema = $id;
		$collection->mcp    = ['access' => $access];

		return $collection;
	}

	/**
	 * UserAuthority whose sole group grants `read` on $allowed collections.
	 *
	 * @param list<string> $allowed
	 */
	private function authorityGranting(array $allowed): UserAuthority
	{
		$group = new AccessGroupData([
			'id'          => 'blogger',
			'description' => 'test fixture',
			'permissions' => [
				'collections' => ['operations' => ['read'], 'all' => false, 'allowed' => $allowed],
			],
		]);

		return new UserAuthority(isAdmin: false, groups: [$group]);
	}

	// ─── Response shape ───────────────────────────────────────────────────────

	public function testReadReturnsFlatResourceContent(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$blog = $this->collection();
		$this->collectionFetcher->method('fetchCollection')->willReturn($blog);
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([['id' => 'a']]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$result = $this->resource->read('blog');

		// Flat {text, mimeType}. The absence of 'contents' is the assertion that
		// matters: returning a pre-built ReadResourceResult made it the *text* of
		// the SDK's own envelope, burying the payload two levels deep for every
		// client. The old tests asserted the envelope and so locked the bug in.
		$this->assertArrayNotHasKey('contents', $result);
		$this->assertArrayHasKey('text', $result);
		$this->assertArrayHasKey('mimeType', $result);
		$this->assertSame('application/json', $result['mimeType']);
	}

	public function testReadDecodedJsonHasRequiredKeys(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([['id' => 'a']]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertIsArray($payload);
		$this->assertArrayHasKey('items', $payload);
		$this->assertArrayHasKey('total', $payload);
		$this->assertArrayHasKey('truncated', $payload);
	}

	// ─── Error paths ─────────────────────────────────────────────────────────

	public function testReadThrowsWhenCollectionNotFound(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/unknown/');

		$this->resource->read('unknown');
	}

	public function testReadThrowsWhenCollectionInaccessibleToPersona(): void
	{
		$this->personaContext->set(McpPersona::PUBLIC_);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection('auth'));
		$this->schemaResolver->method('isAccessibleTo')->willReturn(false);

		$this->expectException(ToolCallException::class);

		$this->resource->read('auth');
	}

	// ─── Truncation ───────────────────────────────────────────────────────────

	public function testReadCapsItemsAtFifty(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$manyItems = [];
		for ($i = 1; $i <= 60; $i++) {
			$manyItems[] = ['id' => "item-{$i}"];
		}
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($manyItems));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/x');

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertCount(50, $payload['items']);
	}

	public function testReadSetsTruncatedTrueAndEmitsHintWhenOver50(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$manyItems = [];
		for ($i = 1; $i <= 55; $i++) {
			$manyItems[] = ['id' => "item-{$i}"];
		}
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($manyItems));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/x');

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertTrue($payload['truncated']);
		$this->assertArrayHasKey('hint', $payload);
		$this->assertStringContainsString('query_collection', $payload['hint']);
		$this->assertStringContainsString('blog', $payload['hint']);
	}

	public function testReadSetsTruncatedFalseAndNoHintWhenAtOrUnder50(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$items = [];
		for ($i = 1; $i <= 10; $i++) {
			$items[] = ['id' => "item-{$i}"];
		}
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($items));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/x');

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertFalse($payload['truncated']);
		$this->assertArrayNotHasKey('hint', $payload);
	}

	// ─── Draft filtering ─────────────────────────────────────────────────────

	public function testPublicPersonaStripsDraftItems(): void
	{
		$this->personaContext->set(McpPersona::PUBLIC_);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'published', 'draft' => false],
			['id' => 'is-draft', 'draft' => true],
			['id' => 'also-published'],
		]));
		$this->urlBuilder->method('buildUrl')->willReturnCallback(
			static fn (CollectionData $c, array $obj): string => '/blog/' . $obj['id'],
		);

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertSame(2, $payload['total']);
		$this->assertCount(2, $payload['items']);

		$ids = array_column($payload['items'], 'id');
		$this->assertContains('published', $ids);
		$this->assertContains('also-published', $ids);
		$this->assertNotContains('is-draft', $ids);
	}

	public function testAdminPersonaRetainsDraftItems(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'published', 'draft' => false],
			['id' => 'is-draft', 'draft' => true],
		]));
		$this->urlBuilder->method('buildUrl')->willReturnCallback(
			static fn (CollectionData $c, array $obj): string => '/blog/' . $obj['id'],
		);

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertSame(2, $payload['total']);
		$this->assertCount(2, $payload['items']);

		$ids = array_column($payload['items'], 'id');
		$this->assertContains('published', $ids);
		$this->assertContains('is-draft', $ids);
	}

	public function testAuthenticatedPersonaWithReadGrantRetainsDraftItems(): void
	{
		// An AUTHENTICATED caller whose resolved UserAuthority grants `read`
		// on this collection behaves like ADMIN for draft visibility — drafts
		// are kept unless explicitly excluded by the caller.
		$this->personaContext->set(McpPersona::AUTHENTICATED);
		$this->grant($this->authorityGranting(['blog']));
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'published', 'draft' => false],
			['id' => 'is-draft', 'draft' => true],
		]));
		$this->urlBuilder->method('buildUrl')->willReturnCallback(
			static fn (CollectionData $c, array $obj): string => '/blog/' . $obj['id'],
		);

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertSame(2, $payload['total']);
		$ids = array_column($payload['items'], 'id');
		$this->assertContains('is-draft', $ids);
	}

	// ─── Task 10 / 10b: group-authority read gate ───────────────────────────

	public function testAuthenticatedPersonaWithoutReadGrantIsDeniedEntirely(): void
	{
		// Unlike QueryCollectionTool/GetObjectTool (which have no per-collection
		// group requirement and only filter drafts), a resource read is a
		// bulk-browse surface: an AUTHENTICATED caller whose authority does not
		// grant `read` on this collection is denied the whole read, not just
		// its drafts. Uses an 'authenticated'-access collection (NOT the
		// 'public' default) so this deny case is isolated from the Task 10b
		// public-collection carve-out proven below.
		$this->personaContext->set(McpPersona::AUTHENTICATED);
		$this->grant($this->authorityGranting(['other-collection']));
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection('blog', 'authenticated'));
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/not accessible/');

		$this->resource->read('blog');
	}

	public function testAuthenticatedPersonaWithNullAuthorityIsDeniedEntirely(): void
	{
		// AUTHENTICATED persona but no resolved UserAuthority at all (e.g. a
		// Bearer request whose identity never resolved) fails closed, same as
		// an explicit deny. Non-public collection — see comment above.
		$this->personaContext->set(McpPersona::AUTHENTICATED);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection('blog', 'authenticated'));
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);

		$this->expectException(ToolCallException::class);

		$this->resource->read('blog');
	}

	public function testAuthenticatedPersonaWithoutReadGrantOnPublicCollectionIsNotDenied(): void
	{
		// Task 10b fix: an AUTHENTICATED caller with NO group grant on this
		// collection is still admitted when the collection's mcp.access is
		// 'public' — closes the privilege inversion the Task 10 review found,
		// where authenticating could SUBTRACT reach an anonymous caller
		// already had (the two tests above prove the opposite case: a
		// non-public collection still denies without a grant).
		$this->personaContext->set(McpPersona::AUTHENTICATED);
		$this->grant($this->authorityGranting(['other-collection']));
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection('blog', 'public'));
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'published', 'draft' => false],
		]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/published');

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertSame(1, $payload['total']);
		$this->assertSame('published', $payload['items'][0]['id']);
	}

	public function testAuthenticatedPersonaWithReadGrantSeesPublishedItems(): void
	{
		// Baseline allow-path: authority grants read on the exact collection.
		$this->personaContext->set(McpPersona::AUTHENTICATED);
		$this->grant($this->authorityGranting(['blog']));
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'published', 'draft' => false],
		]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/published');

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertSame(1, $payload['total']);
		$this->assertSame('published', $payload['items'][0]['id']);
	}

	public function testPublicPersonaTotalReflectsPostFilterCount(): void
	{
		// `total` is intentionally what the caller can see, not the raw index size.
		// A public caller hitting a collection of 5 items (2 drafts) sees total: 3.
		$this->personaContext->set(McpPersona::PUBLIC_);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'a', 'draft' => false],
			['id' => 'b', 'draft' => true],
			['id' => 'c', 'draft' => false],
			['id' => 'd', 'draft' => true],
			['id' => 'e', 'draft' => false],
		]));
		$this->urlBuilder->method('buildUrl')->willReturn('/x');

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertSame(3, $payload['total'], 'total should be the post-filter count for public callers');
		$this->assertCount(3, $payload['items']);
	}

	// ─── Field stripping ─────────────────────────────────────────────────────

	public function testNonExposedPropertiesAreStrippedFromItems(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn(['secret', 'internal_notes']);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'a', 'title' => 'Hello', 'secret' => 'private-data', 'internal_notes' => 'admin-only'],
		]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$item = $payload['items'][0];
		$this->assertArrayHasKey('title', $item);
		$this->assertArrayNotHasKey('secret', $item);
		$this->assertArrayNotHasKey('internal_notes', $item);
	}

	// ─── URL decoration ───────────────────────────────────────────────────────

	public function testEachItemHasUrlKeyFromUrlBuilder(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$blog = $this->collection();
		$this->collectionFetcher->method('fetchCollection')->willReturn($blog);
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'post-1'],
			['id' => 'post-2'],
		]));
		$this->urlBuilder->expects($this->exactly(2))
			->method('buildUrl')
			->willReturnCallback(
				static fn (CollectionData $c, array $obj): string => '/blog/' . $obj['id'],
			);

		$result  = $this->resource->read('blog');
		$payload = json_decode((string)$result['text'], true);

		$this->assertSame('/blog/post-1', $payload['items'][0]['url']);
		$this->assertSame('/blog/post-2', $payload['items'][1]['url']);
	}

	// ─── Owned collections: non-admin callers read what anonymous ones do ────

	/** Give the caller these grants on the persona context and as the request's member actor. */
	private function grant(UserAuthority $authority): void
	{
		$this->personaContext->setAuthority($authority);
		$this->actors->set(new Actor(ActorKind::Member, 'member-1', 'auth', $authority, []));
	}

	private function ownedCollection(string $access = 'public'): CollectionData
	{
		$collection                = $this->collection('blog', $access);
		$collection->ownerProperty = 'owner';

		return $collection;
	}

	/**
	 * Both the resource's own gate and the PersonaContext run over a REAL
	 * McpSchemaResolver, so the owned rule is exercised exactly as in
	 * production. The shaper keeps the mock.
	 */
	private function ownedResource(McpPersona $persona, ?UserAuthority $authority = null): CollectionResource
	{
		$realResolver = new McpSchemaResolver(
			$this->createStub(SchemaFetcher::class),
			new McpDescriptionResolver(),
			$this->createStub(CollectionRepository::class),
		);
		$actors = $persona === McpPersona::AUTHENTICATED && $authority instanceof UserAuthority ? memberActors($authority) : new ActorContext();
		$context = new PersonaContext($this->collectionFetcher, $realResolver, mcpGuard($this, ['blog' => 'owner']), $actors);
		$context->set($persona);
		$context->setAuthority($authority);

		return new CollectionResource(
			$this->collectionFetcher,
			$this->indexReader,
			$realResolver,
			new McpObjectShaper($this->schemaResolver, $this->createStub(ContentRenderer::class), $this->urlBuilder),
			$context,
		);
	}

	public function testReadServesAnOwnedPublicCollectionWithoutDraftsToAnAnonymousCaller(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->ownedCollection());
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([['id' => 'a'], ['id' => 'b', 'draft' => true]]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$payload = json_decode((string)$this->ownedResource(McpPersona::PUBLIC_)->read('blog')['text'], true);

		$this->assertSame(['a'], array_column($payload['items'], 'id'));
	}

	public function testReadServesAMemberTheirOwnDraftsAndOthersPublishedOnAnOwnedPublicCollection(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->ownedCollection());
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'theirs', 'owner' => 'sam'],
			['id' => 'their-draft', 'owner' => 'sam', 'draft' => true],
			['id' => 'my-draft', 'owner' => 'member-1', 'draft' => true],
		]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$payload = json_decode((string)$this->ownedResource(McpPersona::AUTHENTICATED, $this->authorityGranting(['blog']))->read('blog')['text'], true);

		$this->assertSame(['theirs', 'my-draft'], array_column($payload['items'], 'id'));
	}

	public function testReadServesAMemberOnlyTheirOwnOnAnOwnedCollectionThatIsNotPublic(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->ownedCollection('authenticated'));
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'theirs', 'owner' => 'sam'],
			['id' => 'mine', 'owner' => 'member-1'],
		]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$payload = json_decode((string)$this->ownedResource(McpPersona::AUTHENTICATED, $this->authorityGranting(['blog']))->read('blog')['text'], true);

		$this->assertSame(['mine'], array_column($payload['items'], 'id'));
	}

	public function testReadServesAnOwnedCollectionWithDraftsToAdmin(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->ownedCollection('authenticated'));
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([['id' => 'a'], ['id' => 'b', 'draft' => true]]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$payload = json_decode((string)$this->ownedResource(McpPersona::ADMIN)->read('blog')['text'], true);

		$this->assertSame(['a', 'b'], array_column($payload['items'], 'id'));
	}
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Auth\Service;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpDescriptionResolver;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class PersonaContextTest extends TestCase
{
	/**
	 * Fresh PersonaContext wired with a real (non-mock) McpSchemaResolver
	 * stand-in whose forCollection() derives 'access' from whatever
	 * CollectionData is passed to CollectionFetcher::fetchCollection() —
	 * exercises canReadCollection()'s public-collection carve-out against
	 * real fixture data rather than a mock the caller has to hand-configure
	 * per test.
	 */
	private function context(): PersonaContext
	{
		$fetcher = $this->createStub(CollectionFetcher::class);
		$fetcher->method('fetchCollection')->willReturnCallback(
			fn (string $id): ?CollectionData => $this->fixtureCollections[$id] ?? null,
		);

		$schemaResolver = $this->createStub(McpSchemaResolver::class);
		$schemaResolver->method('forCollection')->willReturnCallback(
			static fn (CollectionData $c): array => [
				'access'        => (string)($c->mcp['access'] ?? 'admin'),
				'owned'         => false,
				'description'   => null,
				'resource'      => true,
				'titleProperty' => '',
			],
		);

		return new PersonaContext($fetcher, $schemaResolver, mcpGuard($this), $this->actors());
	}

	/** @var array<string,CollectionData> */
	private array $fixtureCollections = [];

	/** The request's actor, shared by every context a test builds. */
	private ?ActorContext $actors = null;

	private function actors(): ActorContext
	{
		return $this->actors ??= new ActorContext();
	}

	/**
	 * Give the caller read on $allowed, as McpRequestAuthorizer does for an
	 * OAuth member: on the persona context and on the request's actor.
	 *
	 * @param list<string> $allowed
	 */
	private function grant(PersonaContext $context, array $allowed): void
	{
		$authority = $this->authorityGranting($allowed);
		$context->setAuthority($authority);
		$this->actors()->set(new Actor(ActorKind::Member, 'member-1', 'auth', $authority, ['cms:read']));
	}

	private function registerCollection(string $id, string $access): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = $id;
		$collection->schema = $id;
		$collection->mcp    = ['access' => $access];

		$this->fixtureCollections[$id] = $collection;

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

	public function testFreshContextIsUnresolved(): void
	{
		$context = $this->context();

		$this->assertFalse($context->isResolved());
	}

	public function testCurrentThrowsBeforeSet(): void
	{
		$context = $this->context();

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Persona has not been resolved');

		$context->current();
	}

	public function testSetMakesContextResolved(): void
	{
		$context = $this->context();
		$context->set(McpPersona::ADMIN);

		$this->assertTrue($context->isResolved());
		$this->assertSame(McpPersona::ADMIN, $context->current());
	}

	public function testSetOverwritesPreviousValue(): void
	{
		// Mid-request persona changes shouldn't happen in production, but the
		// behavior should be predictable: last-write-wins, no exception.
		$context = $this->context();
		$context->set(McpPersona::ADMIN);
		$context->set(McpPersona::PUBLIC_);

		$this->assertSame(McpPersona::PUBLIC_, $context->current());
	}

	// ── Scopes ──────────────────────────────────────────────────────────────────

	public function testGetScopesDefaultsToEmptyArray(): void
	{
		$context = $this->context();

		$this->assertSame([], $context->getScopes());
	}

	public function testSetScopesStoresScopes(): void
	{
		$context = $this->context();
		$context->setScopes(['mcp:tools', 'mcp:resources']);

		$this->assertSame(['mcp:tools', 'mcp:resources'], $context->getScopes());
	}

	public function testSetScopesOverwritesPreviousScopes(): void
	{
		// Last-write-wins — consistent with persona behaviour.
		$context = $this->context();
		$context->setScopes(['mcp:tools']);
		$context->setScopes(['mcp:resources', 'cms:read']);

		$this->assertSame(['mcp:resources', 'cms:read'], $context->getScopes());
	}

	public function testSetScopesWithEmptyArrayClearsScopes(): void
	{
		$context = $this->context();
		$context->setScopes(['mcp:tools']);
		$context->setScopes([]);

		$this->assertSame([], $context->getScopes());
	}

	public function testScopesAreIndependentOfPersona(): void
	{
		// Scopes and persona are orthogonal — setting one doesn't affect the other.
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$context->setScopes(['mcp:tools']);

		$this->assertSame(McpPersona::AUTHENTICATED, $context->current());
		$this->assertSame(['mcp:tools'], $context->getScopes());
	}

	// ── canReadCollection (Task 10b) ─────────────────────────────────────────────

	public function testCanReadCollectionTrueForAdminRegardlessOfCollection(): void
	{
		$this->registerCollection('blog', 'admin');
		$context = $this->context();
		$context->set(McpPersona::ADMIN);

		$this->assertTrue($context->canReadCollection('blog'));
	}

	public function testCanReadCollectionTrueForPublicCollectionWithNoAuthorityAtAll(): void
	{
		// The public-collection carve-out: even PUBLIC_ (no authority resolved
		// at all — mirrors an anonymous/API-key-less caller) can read a
		// mcp.access:'public' collection.
		$this->registerCollection('blog', 'public');
		$context = $this->context();
		$context->set(McpPersona::PUBLIC_);

		$this->assertTrue($context->canReadCollection('blog'));
	}

	public function testCanReadCollectionFalseForNonPublicCollectionWithNoAuthority(): void
	{
		$this->registerCollection('blog', 'authenticated');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);

		$this->assertFalse($context->canReadCollection('blog'));
	}

	public function testCanReadCollectionTrueWhenAuthorityGrantsReadOnNonPublicCollection(): void
	{
		$this->registerCollection('blog', 'authenticated');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$this->grant($context, ['blog']);

		$this->assertTrue($context->canReadCollection('blog'));
	}

	public function testCanReadCollectionFalseWhenAuthorityGrantsReadElsewhereOnlyAndCollectionNotPublic(): void
	{
		$this->registerCollection('blog', 'authenticated');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$this->grant($context, ['other-collection']);

		$this->assertFalse($context->canReadCollection('blog'));
	}

	public function testCanReadCollectionTrueWithoutAnyGrantOnPublicCollectionEvenWhenAuthorityResolvedButEmpty(): void
	{
		// The exact privilege-inversion regression: an AUTHENTICATED caller
		// whose authority grants NOTHING must still read a public collection —
		// authenticating must never subtract reach an anonymous caller has.
		$this->registerCollection('blog', 'public');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$this->grant($context, []);

		$this->assertTrue($context->canReadCollection('blog'));
	}

	public function testCanReadCollectionAcceptsAPreFetchedCollectionDataWithoutCallingTheFetcher(): void
	{
		// Passing $collectionData directly must short-circuit the internal
		// CollectionFetcher lookup — proven by NOT registering the collection
		// in the fixture map at all; if canReadCollection() ignored the
		// passed-in CollectionData and fetched by id instead, fetchCollection()
		// would return null and this would incorrectly resolve to false.
		$context = $this->context();
		$context->set(McpPersona::PUBLIC_);

		$adHoc         = new CollectionData();
		$adHoc->id     = 'not-in-fixture-map';
		$adHoc->schema = 'not-in-fixture-map';
		$adHoc->mcp    = ['access' => 'public'];

		$this->assertTrue($context->canReadCollection('not-in-fixture-map', $adHoc));
	}

	// ── readScope / canReadCollection consistency ──────────────────────────────

	public function testReadScopeIsPublishedOnlyOnAPublicCollectionWithoutAGrant(): void
	{
		// Public exposure must NEVER imply draft access: readScope() does NOT
		// share canReadCollection()'s public carve-out.
		$this->registerCollection('blog', 'public');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$this->grant($context, []);

		$this->assertEquals(ReadScope::publishedOnly(), $context->readScope('blog'));
		// Yet the same caller CAN read the collection's published content.
		$this->assertTrue($context->canReadCollection('blog'));
	}

	public function testAGrantOnAnUnownedCollectionReadsEverythingDraftsIncluded(): void
	{
		$this->registerCollection('blog', 'authenticated');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$this->grant($context, ['blog']);

		$this->assertTrue($context->readScope('blog')->isAll());
		$this->assertTrue($context->canReadCollection('blog'));
	}

	// ── isCollectionExposedPublic (Task 10b fix round 1, finding #2) ────────────

	public function testIsCollectionExposedPublicTrueForPublicCollectionIndependentOfPersonaOrAuthority(): void
	{
		// Exposure alone — no persona set at all, no authority resolved.
		// McpServerFactory::guardHandler() calls this BEFORE the scope check
		// even runs, so it must never depend on either.
		$this->registerCollection('blog', 'public');
		$context = $this->context();

		$this->assertTrue($context->isCollectionExposedPublic('blog'));
	}

	public function testIsCollectionExposedPublicFalseForNonPublicCollectionEvenWithAGrant(): void
	{
		// The critical distinction from canReadCollection(): a real
		// access-group grant must NOT make this true — only genuine public
		// exposure may skip the scope layer. A grant without consent still
		// needs the consent (scope) check.
		$this->registerCollection('blog', 'authenticated');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$this->grant($context, ['blog']);

		$this->assertFalse($context->isCollectionExposedPublic('blog'));
		// canReadCollection() (the group-layer question) is still true —
		// proving these two methods answer genuinely different questions.
		$this->assertTrue($context->canReadCollection('blog'));
	}

	public function testIsCollectionExposedPublicFalseForUnknownCollection(): void
	{
		$context = $this->context();

		$this->assertFalse($context->isCollectionExposedPublic('does-not-exist'));
	}

	public function testIsCollectionExposedPublicAcceptsAPreFetchedCollectionDataWithoutCallingTheFetcher(): void
	{
		$context = $this->context();

		$adHoc         = new CollectionData();
		$adHoc->id     = 'not-in-fixture-map';
		$adHoc->schema = 'not-in-fixture-map';
		$adHoc->mcp    = ['access' => 'public'];

		$this->assertTrue($context->isCollectionExposedPublic('not-in-fixture-map', $adHoc));
	}

	// ── The collection gate every content tool used to inline ────────────────

	/** @param array<string,CollectionData> $collections */
	private function gateContext(array $collections, McpPersona $persona, bool $accessible = true): PersonaContext
	{
		$fetcher = $this->createStub(CollectionFetcher::class);
		$fetcher->method('fetchCollection')->willReturnCallback(static fn (string $id): ?CollectionData => $collections[$id] ?? null);
		$resolver = $this->createStub(McpSchemaResolver::class);
		$resolver->method('isAccessibleTo')->willReturn($accessible);
		$resolver->method('forCollection')->willReturnCallback(
			static fn (CollectionData $c): array => ['access' => (string)($c->mcp['access'] ?? 'admin'), 'owned' => false, 'description' => null, 'resource' => true, 'titleProperty' => ''],
		);
		$resolver->method('renderCatalog')->willReturn('Available collections and filterable properties:');

		$context = new PersonaContext($fetcher, $resolver, mcpGuard($this), new ActorContext());
		$context->set($persona);

		return $context;
	}

	/**
	 * A context over a REAL McpSchemaResolver, so the owned-collection rule is
	 * exercised where it lives (forCollection()) rather than stubbed away.
	 *
	 * @param array<string,CollectionData> $collections
	 */
	private function ownedContext(array $collections, McpPersona $persona): PersonaContext
	{
		$fetcher = $this->createStub(CollectionFetcher::class);
		$fetcher->method('fetchCollection')->willReturnCallback(static fn (string $id): ?CollectionData => $collections[$id] ?? null);
		$repository = $this->createStub(CollectionRepository::class);
		$repository->method('listAllCollections')->willReturn(array_values($collections));
		$schemas = $this->createStub(SchemaFetcher::class);
		$schemas->method('fetchSchemaForCollection')->willReturn(new SchemaData());

		$context = new PersonaContext($fetcher, new McpSchemaResolver($schemas, new McpDescriptionResolver(), $repository), mcpGuard($this, ['portfolio' => 'owner']), $this->actors());
		$context->set($persona);

		return $context;
	}

	private function registerOwnedCollection(string $id, string $access): CollectionData
	{
		$collection                = $this->registerCollection($id, $access);
		$collection->ownerProperty = 'owner';

		return $collection;
	}

	public function testExposedCollectionReturnsTheRecordWhenTheCallerMayReachIt(): void
	{
		$blog = $this->registerCollection('blog', 'public');

		$this->assertSame($blog, $this->gateContext(['blog' => $blog], McpPersona::PUBLIC_)->exposedCollection('blog'));
	}

	public function testExposedCollectionRefusesAnUnknownCollectionWithTheDiscoveryHint(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('Collection "ghost" not found. Use list_collections to see available collections.');

		$this->gateContext([], McpPersona::ADMIN)->exposedCollection('ghost');
	}

	public function testExposedCollectionRefusesOneTheCallerMayNotReachNamingWhatTheyCanDo(): void
	{
		$blog = $this->registerCollection('blog', 'admin');

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('Collection "blog" is not available to the current caller. Use list_collections to see what you can describe.');

		$this->gateContext(['blog' => $blog], McpPersona::PUBLIC_, accessible: false)->exposedCollection('blog', 'describe');
	}

	public function testVisibleCollectionsKeepsOnlyExposedReadableOnes(): void
	{
		$blog  = $this->registerCollection('blog', 'public');
		$notes = $this->registerCollection('notes', 'public');

		$this->assertSame([$blog, $notes], $this->gateContext(['blog' => $blog, 'notes' => $notes], McpPersona::ADMIN)->visibleCollections([$blog, $notes]));
		$this->assertSame([], $this->gateContext(['blog' => $blog], McpPersona::PUBLIC_, accessible: false)->visibleCollections([$blog]));
	}

	public function testCatalogDescriptionAppendsTheCatalogWhenThereIsOne(): void
	{
		$context = $this->gateContext([], McpPersona::ADMIN);

		$this->assertSame("Base.\n\nAvailable collections and filterable properties:", $context->catalogDescription(McpPersona::ADMIN, 'Base.'));
	}

	// ── Owned collections: a member reads their own objects ─────────────────

	public static function nonAdminPersonas(): array
	{
		return [
			'authenticated' => [McpPersona::AUTHENTICATED],
			'public'        => [McpPersona::PUBLIC_],
		];
	}

	public function testAnOwnedCollectionKeepsItsDeclaredAccessThroughExposedCollection(): void
	{
		$portfolio = $this->registerOwnedCollection('portfolio', 'public');

		foreach ([McpPersona::PUBLIC_, McpPersona::AUTHENTICATED, McpPersona::ADMIN] as $persona) {
			$this->assertSame($portfolio, $this->ownedContext(['portfolio' => $portfolio], $persona)->exposedCollection('portfolio'));
		}
	}

	public function testAnOwnedAdminCollectionIsRefusedWithTheOrdinaryMessage(): void
	{
		$portfolio = $this->registerOwnedCollection('portfolio', 'admin');

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('Collection "portfolio" is not available to the current caller. Use list_collections to see what you can query.');

		$this->ownedContext(['portfolio' => $portfolio], McpPersona::AUTHENTICATED)->exposedCollection('portfolio');
	}

	public function testAnonymousCallerReadsAnOwnedPublicCollectionButNotItsDrafts(): void
	{
		$portfolio = $this->registerOwnedCollection('portfolio', 'public');
		$context   = $this->ownedContext(['portfolio' => $portfolio], McpPersona::PUBLIC_);

		$this->assertTrue($context->canReadCollection('portfolio'));
		$this->assertEquals(ReadScope::publishedOnly(), $context->readScope('portfolio'));
	}

	public function testAGrantedMemberOnAnOwnedPublicCollectionReadsTheirOwnPlusEveryonesPublished(): void
	{
		$portfolio = $this->registerOwnedCollection('portfolio', 'public');
		$context   = $this->ownedContext(['portfolio' => $portfolio], McpPersona::AUTHENTICATED);
		$this->grant($context, ['portfolio']);

		$scope = $context->readScope('portfolio');
		$this->assertTrue($context->canReadCollection('portfolio'));
		$this->assertTrue($scope->matches(['id' => 'mine', 'owner' => 'member-1', 'draft' => true]));
		$this->assertTrue($scope->matches(['id' => 'theirs', 'owner' => 'sam', 'draft' => false]));
		$this->assertFalse($scope->matches(['id' => 'their-draft', 'owner' => 'sam', 'draft' => true]));
	}

	public function testAGrantedMemberOnAnOwnedCollectionThatIsNotPublicReadsOnlyTheirOwn(): void
	{
		$portfolio = $this->registerOwnedCollection('portfolio', 'authenticated');
		$context   = $this->ownedContext(['portfolio' => $portfolio], McpPersona::AUTHENTICATED);
		$this->grant($context, ['portfolio']);

		$scope = $context->readScope('portfolio');
		$this->assertTrue($context->canReadCollection('portfolio', $portfolio));
		$this->assertSame([$portfolio], $context->visibleCollections([$portfolio]));
		$this->assertTrue($scope->matches(['id' => 'mine', 'owner' => 'member-1', 'draft' => true]));
		$this->assertFalse($scope->matches(['id' => 'theirs', 'owner' => 'sam', 'draft' => false]));
	}

	public function testTheSameGrantOpensAnUnownedCollectionThatIsNotPublicCompletely(): void
	{
		$blog    = $this->registerCollection('blog', 'authenticated');
		$context = $this->ownedContext(['blog' => $blog], McpPersona::AUTHENTICATED);
		$this->grant($context, ['blog']);

		$this->assertTrue($context->canReadCollection('blog'));
		$this->assertTrue($context->readScope('blog')->isAll());
	}

	public function testAdminSeesAnOwnedCollectionAndItsDrafts(): void
	{
		$portfolio = $this->registerOwnedCollection('portfolio', 'authenticated');
		$context   = $this->ownedContext(['portfolio' => $portfolio], McpPersona::ADMIN);

		$this->assertTrue($context->canReadCollection('portfolio'));
		$this->assertTrue($context->readScope('portfolio')->isAll());
		$this->assertSame([$portfolio], $context->visibleCollections([$portfolio]));
	}

	#[DataProvider('nonAdminPersonas')]
	public function testAnOwnedPublicCollectionIsVisibleAndCatalogedForNonAdminPersonas(McpPersona $persona): void
	{
		$portfolio = $this->registerOwnedCollection('portfolio', 'public');
		$context   = $this->ownedContext(['portfolio' => $portfolio], $persona);

		$this->assertSame([$portfolio], $context->visibleCollections([$portfolio]));
		$this->assertStringContainsString('- portfolio', $context->catalogDescription($persona, 'Base.'));
	}

	public function testExposedCollectionServesAnUnownedCollectionToAuthenticated(): void
	{
		$blog = $this->registerCollection('blog', 'public');

		$this->assertSame($blog, $this->gateContext(['blog' => $blog], McpPersona::AUTHENTICATED)->exposedCollection('blog'));
	}

	public function testVisibleCollectionsKeepsAnUnownedCollectionForAuthenticated(): void
	{
		$blog = $this->registerCollection('blog', 'public');

		$this->assertSame([$blog], $this->gateContext(['blog' => $blog], McpPersona::AUTHENTICATED)->visibleCollections([$blog]));
	}
}

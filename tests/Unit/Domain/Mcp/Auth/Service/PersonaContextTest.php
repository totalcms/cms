<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Auth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;

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
				'description'   => null,
				'resource'      => true,
				'titleProperty' => '',
			],
		);

		return new PersonaContext($fetcher, $schemaResolver);
	}

	/** @var array<string,CollectionData> */
	private array $fixtureCollections = [];

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
		$context->setAuthority($this->authorityGranting(['blog']));

		$this->assertTrue($context->canReadCollection('blog'));
	}

	public function testCanReadCollectionFalseWhenAuthorityGrantsReadElsewhereOnlyAndCollectionNotPublic(): void
	{
		$this->registerCollection('blog', 'authenticated');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$context->setAuthority($this->authorityGranting(['other-collection']));

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
		$context->setAuthority($this->authorityGranting([]));

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

	// ── canReadDrafts / canReadCollection consistency (Task 10b) ────────────────

	public function testCanReadDraftsStaysFalseOnAPublicCollectionWithoutAGrant(): void
	{
		// Public exposure must NEVER imply draft access — canReadDrafts()
		// deliberately does NOT share canReadCollection()'s public carve-out.
		$this->registerCollection('blog', 'public');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$context->setAuthority($this->authorityGranting([]));

		$this->assertFalse($context->canReadDrafts('blog'));
		// Yet the same caller CAN read the collection's published content —
		// proving the two rules are deliberately different, not a bug.
		$this->assertTrue($context->canReadCollection('blog'));
	}

	public function testCanReadDraftsAndCanReadCollectionAgreeWhenAuthorityGrantsRead(): void
	{
		$this->registerCollection('blog', 'authenticated');
		$context = $this->context();
		$context->set(McpPersona::AUTHENTICATED);
		$context->setAuthority($this->authorityGranting(['blog']));

		$this->assertTrue($context->canReadDrafts('blog'));
		$this->assertTrue($context->canReadCollection('blog'));
	}
}

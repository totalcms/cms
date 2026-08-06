<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Admin;

use Illuminate\Support\Collection;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Admin\ObjectTools;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class ObjectToolsTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $saver;
	private \PHPUnit\Framework\MockObject\MockObject $updater;
	private \PHPUnit\Framework\MockObject\MockObject $patcher;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $personaContext;
	private \PHPUnit\Framework\MockObject\MockObject $schemaResolver;
	private ObjectTools $tool;

	// Final review fix (Critical #1/#1b) test knobs. schemaResolver/
	// collectionFetcher are configured ONCE in setUp() via willReturnCallback
	// reading these properties — PHPUnit resolves stacked `->method(x)->
	// willReturn(y)` calls on the SAME method in REGISTRATION order (first
	// match wins), so a later `->method('isAccessibleTo')->willReturn(false)`
	// inside an individual test would silently lose to setUp()'s default and
	// never take effect. Mutating these properties from within a test is the
	// reliable way to change a mock's behavior after setUp() has run.
	private bool $exposureAccessible = true;

	private bool $collectionExists = true;

	/** @var list<string> */
	private array $hiddenProperties = [];

	protected function setUp(): void
	{
		$this->saver         = $this->createMock(ObjectSaver::class);
		$this->updater       = $this->createMock(ObjectUpdater::class);
		$this->patcher       = $this->createMock(ObjectPatcher::class);
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);

		// Final review fix (Critical #1/#1b): requireExposed()/stripNonExposed()
		// deps. Default every test to the "wide open, nothing hidden" shape —
		// ADMIN persona, collection resolves, exposure check passes, nothing
		// stripped — so the pre-existing tests below (which exercise binary-field
		// handling / error translation, not the exposure gate itself) keep
		// behaving exactly as before. The exposure-gate / stripping tests below
		// flip the knob properties above to exercise the gate/stripping directly.
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionFetcher->method('fetchCollection')
			->willReturnCallback(fn (): ?CollectionData => $this->collectionExists ? new CollectionData() : null);
		$this->personaContext = $this->createMock(PersonaContext::class);
		$this->personaContext->method('current')->willReturn(McpPersona::ADMIN);
		$this->schemaResolver = $this->createMock(McpSchemaResolver::class);
		$this->schemaResolver->method('isAccessibleTo')
			->willReturnCallback(fn (): bool => $this->exposureAccessible);
		$this->schemaResolver->method('nonExposedProperties')
			->willReturnCallback(fn (): array => $this->hiddenProperties);

		$this->tool = new ObjectTools(
			$this->saver,
			$this->updater,
			$this->patcher,
			$this->schemaFetcher,
			$this->objectFetcher,
			$this->collectionFetcher,
			$this->personaContext,
			$this->schemaResolver,
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $properties
	 */
	private function schema(array $properties): SchemaData
	{
		$schema             = new SchemaData();
		$schema->id         = 'blog';
		$schema->properties = $properties;

		return $schema;
	}

	private function textOnlySchema(): SchemaData
	{
		// What a sync-friendly content schema looks like — every field is
		// either a primitive or a string. No image/file/gallery/depot, so
		// the tools accept it.
		return $this->schema([
			'id'      => ['field' => 'id'],
			'title'   => ['field' => 'text'],
			'body'    => ['field' => 'markdown'],
			'status'  => ['field' => 'select'],
			'draft'   => ['field' => 'toggle'],
		]);
	}

	private function blogObject(string $id): ObjectData
	{
		// Real ObjectData has a Collection of PropertyData, but the handlers
		// only call ->toArray() on the result. Stub a minimal instance that
		// satisfies that contract without dragging in PropertyData factories.
		$obj             = $this->createStub(ObjectData::class);
		$obj->id         = $id;
		$obj->properties = new Collection();
		$obj->method('toArray')->willReturn(['id' => $id]);

		return $obj;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function objectWith(string $id, array $data): ObjectData
	{
		$obj             = $this->createStub(ObjectData::class);
		$obj->id         = $id;
		$obj->properties = new Collection();
		$obj->method('toArray')->willReturn(['id' => $id] + $data);

		return $obj;
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsAllThreeToolsWithAdminAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$create = $registry->get('create_object');
		$update = $registry->get('update_object');
		$patch  = $registry->get('patch_object');

		$this->assertNotNull($create);
		$this->assertNotNull($update);
		$this->assertNotNull($patch);
		$this->assertSame('admin', $create->access);
		$this->assertSame('admin', $update->access);
		$this->assertSame('admin', $patch->access);
	}

	/**
	 * Task 8 fix round, Important #4: pin the exact ToolRequirement triple
	 * each write tool declares — nothing previously asserted these, so a
	 * wrong domain string or flipped operation would silently deny-all and
	 * every other test would still pass.
	 */
	public function testWriteToolsDeclareTheExpectedRequirementTriple(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$expected = [
			'create_object' => ['objects', 'create', 'collection'],
			'update_object' => ['objects', 'update', 'collection'],
			// patch is update semantics (existing object, subset write).
			'patch_object'  => ['objects', 'update', 'collection'],
		];

		foreach ($expected as $name => [$domain, $operation, $collectionArg]) {
			$requires = $registry->get($name)->requires;
			$this->assertNotNull($requires, "Tool $name should declare a ToolRequirement");
			$this->assertSame($domain, $requires->domain, "Tool $name requirement domain");
			$this->assertSame($operation, $requires->operation, "Tool $name requirement operation");
			$this->assertSame($collectionArg, $requires->collectionArg, "Tool $name requirement collectionArg");
		}
	}

	public function testUpdateIsAnnotatedIdempotent(): void
	{
		// update_object: same payload twice produces the same end state.
		// create_object errors on the second call (id already exists), so it
		// is NOT idempotent. Annotation hints back this distinction.
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$createAnn = $registry->get('create_object')->annotations;
		$updateAnn = $registry->get('update_object')->annotations;

		$this->assertNotNull($createAnn);
		$this->assertNotNull($updateAnn);
		$this->assertFalse($createAnn->idempotentHint);
		$this->assertTrue($updateAnn->idempotentHint);
		// Neither tool is destructive — they don't remove anything.
		$this->assertFalse($createAnn->destructiveHint);
		$this->assertFalse($updateAnn->destructiveHint);
	}

	// ─── create_object happy paths ───────────────────────────────────────────

	public function testCreateDispatchesToSaverAndReturnsObjectArray(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->with('blog')
			->willReturn($this->textOnlySchema());

		$saved = $this->blogObject('my-first-post');
		$this->saver->expects($this->once())
			->method('saveObject')
			->with('blog', $this->callback(static fn (array $data): bool => ($data['title'] ?? '') === 'My First Post'))
			->willReturn($saved);

		$result = $this->tool->createHandler(
			collection: 'blog',
			data: ['title' => 'My First Post', 'body' => '# Hello'],
		);

		$this->assertSame(['id' => 'my-first-post'], $result);
	}

	public function testCreateStampsExplicitIdIntoPayload(): void
	{
		// When the caller passes `id`, the tool merges it into the payload so
		// the factory uses it instead of running autogen. Without this stamp
		// the LLM's explicit choice would be silently overridden.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->textOnlySchema());

		$this->saver->expects($this->once())
			->method('saveObject')
			->with('blog', $this->callback(static fn (array $data): bool => ($data['id'] ?? '') === 'custom-slug'))
			->willReturn($this->blogObject('custom-slug'));

		$this->tool->createHandler(
			collection: 'blog',
			data: ['title' => 'Hi'],
			id: 'custom-slug',
		);
	}

	// ─── create_object binary fields (payload-level) ─────────────────────────

	public function testCreateSucceedsWhenBinaryFieldExistsButIsOmitted(): void
	{
		// The schema has a top-level image field, but the payload doesn't set
		// it. The tool must write the text content and leave the image unset —
		// not refuse the whole collection.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'        => ['field' => 'id'],
				'title'     => ['field' => 'text'],
				'thumbnail' => ['field' => 'image'],
			]));

		$this->saver->expects($this->once())
			->method('saveObject')
			->with('blog', $this->callback(static fn (array $data): bool => ($data['title'] ?? '') === 'Home'
					&& !array_key_exists('thumbnail', $data)))
			->willReturn($this->blogObject('home'));

		$result = $this->tool->createHandler(collection: 'blog', data: ['title' => 'Home']);

		$this->assertSame(['id' => 'home'], $result);
	}

	public function testCreateStripsEmptyBinaryEchoFromPayload(): void
	{
		// An agent that echoes a blank binary field back (thumbnail: "") is
		// allowed — the empty value is treated as "not writing" and stripped
		// before it reaches the saver.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'        => ['field' => 'id'],
				'title'     => ['field' => 'text'],
				'thumbnail' => ['field' => 'image'],
			]));

		$this->saver->expects($this->once())
			->method('saveObject')
			->with('blog', $this->callback(static fn (array $data): bool => !array_key_exists('thumbnail', $data)))
			->willReturn($this->blogObject('home'));

		$this->tool->createHandler(collection: 'blog', data: ['title' => 'Home', 'thumbnail' => '']);
	}

	public function testCreateRefusesWhenPayloadSetsBinaryFields(): void
	{
		// When the payload actually puts values into binary fields, refuse and
		// name every offender in one error so the agent learns what to drop.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'       => ['field' => 'id'],
				'cover'    => ['field' => 'image'],
				'attached' => ['field' => 'file'],
				'pics'     => ['field' => 'gallery'],
			]));

		$this->saver->expects($this->never())->method('saveObject');

		try {
			$this->tool->createHandler(collection: 'asset-heavy', data: [
				'cover'    => 'photo.jpg',
				'attached' => 'doc.pdf',
				'pics'     => ['a.jpg'],
			]);
			$this->fail('Expected ToolCallException.');
		} catch (ToolCallException $e) {
			$msg = $e->getMessage();
			$this->assertStringContainsString('cover', $msg);
			$this->assertStringContainsString('attached', $msg);
			$this->assertStringContainsString('pics', $msg);
		}
	}

	public function testCreateMissingCollectionSurfacesDiscoveryHint(): void
	{
		// SchemaFetcher throws UnexpectedValueException for unknown
		// collections. The tool should translate it to a hint pointing at
		// list_collections instead of leaking the raw exception text.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willThrowException(new \UnexpectedValueException('Collection for Schema not found: nope'));

		try {
			$this->tool->createHandler(collection: 'nope', data: []);
			$this->fail('Expected ToolCallException.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('nope', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	public function testCreateConvertsDuplicateIdToToolError(): void
	{
		// ObjectSaver throws DomainException when an object with the same
		// id already exists. Should bubble out as a ToolCallException so
		// the SDK surfaces it cleanly.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->textOnlySchema());

		$this->saver->method('saveObject')
			->willThrowException(new \DomainException('Object with id my-post already exists in blog'));

		try {
			$this->tool->createHandler(collection: 'blog', data: ['title' => 'Dup'], id: 'my-post');
			$this->fail('Expected ToolCallException for duplicate id.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blog', $e->getMessage());
			$this->assertStringContainsString('exists', strtolower($e->getMessage()));
		}
	}

	// ─── update_object ───────────────────────────────────────────────────────

	public function testUpdateDispatchesToUpdaterWithIdStampedIntoData(): void
	{
		// ObjectUpdater::updateObject validates that the resolved object id
		// matches the route arg. The tool stamps `id` onto the data payload
		// so the factory builds the object with that id and the equality
		// check passes.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->textOnlySchema());

		$this->updater->expects($this->once())
			->method('updateObject')
			->with(
				'blog',
				'my-post',
				$this->callback(static fn (array $data): bool => ($data['id'] ?? '') === 'my-post'
						&& ($data['title'] ?? '') === 'Updated'),
			)
			->willReturn($this->blogObject('my-post'));

		$result = $this->tool->updateHandler(
			collection: 'blog',
			id: 'my-post',
			data: ['title' => 'Updated'],
		);

		$this->assertSame(['id' => 'my-post'], $result);
	}

	public function testUpdatePreservesExistingBinaryWhenOmitted(): void
	{
		// The crux of the data-safety fix: updateObject is a full replace, so
		// an edit that omits the image would wipe it. The tool must carry the
		// existing object's binary value forward into the payload.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'        => ['field' => 'id'],
				'title'     => ['field' => 'text'],
				'thumbnail' => ['field' => 'image'],
			]));

		$this->objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('blog', 'my-post')
			->willReturn($this->objectWith('my-post', ['title' => 'Old', 'thumbnail' => 'existing.jpg']));

		$this->updater->expects($this->once())
			->method('updateObject')
			->with(
				'blog',
				'my-post',
				$this->callback(static fn (array $data): bool => ($data['title'] ?? '') === 'New'
						&& ($data['thumbnail'] ?? null) === 'existing.jpg'),
			)
			->willReturn($this->blogObject('my-post'));

		$this->tool->updateHandler(collection: 'blog', id: 'my-post', data: ['title' => 'New']);
	}

	public function testUpdateRefusesWhenPayloadSetsBinaryField(): void
	{
		// Trying to write a binary value through update is still refused.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'        => ['field' => 'id'],
				'thumbnail' => ['field' => 'image'],
			]));

		$this->updater->expects($this->never())->method('updateObject');
		$this->objectFetcher->expects($this->never())->method('fetchObject');

		try {
			$this->tool->updateHandler(collection: 'blog', id: 'home', data: ['thumbnail' => 'new.jpg']);
			$this->fail('Expected ToolCallException for binary write.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('thumbnail', $e->getMessage());
		}
	}

	public function testUpdateConvertsNotFoundToToolError(): void
	{
		// ObjectUpdater throws UnexpectedValueException when the underlying
		// fetch fails or the id mismatches.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->textOnlySchema());

		$this->updater->method('updateObject')
			->willThrowException(new \UnexpectedValueException('Object missing-post not found in blog'));

		try {
			$this->tool->updateHandler(collection: 'blog', id: 'missing-post', data: ['title' => 'X']);
			$this->fail('Expected ToolCallException for missing object.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blog', $e->getMessage());
			$this->assertStringContainsString('missing-post', $e->getMessage());
		}
	}

	// ─── patch_object ────────────────────────────────────────────────────────

	public function testPatchDispatchesOnlyProvidedFieldsWithIdStamped(): void
	{
		// The whole point of patch: the handler passes ONLY the provided
		// fields (plus the stamped id) to ObjectPatcher, which merges them
		// over the stored object. No round-trip through the full body.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->textOnlySchema());
		$this->objectFetcher->method('existsObject')->willReturn(true);

		$this->patcher->expects($this->once())
			->method('patchObject')
			->with(
				'blog',
				'my-post',
				$this->callback(static fn (array $data): bool => ($data['id'] ?? '') === 'my-post'
						&& ($data['title'] ?? '') === 'Patched'
						&& !array_key_exists('body', $data)),
			)
			->willReturn($this->blogObject('my-post'));

		$result = $this->tool->patchHandler(
			collection: 'blog',
			id: 'my-post',
			data: ['title' => 'Patched'],
		);

		$this->assertSame(['id' => 'my-post'], $result);
	}

	public function testPatchOverridesDivergentIdInPayload(): void
	{
		// An agent that slips a different id into the payload must not rename
		// the object (or trip the updater's equality assertion) — the route id
		// wins.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->textOnlySchema());
		$this->objectFetcher->method('existsObject')->willReturn(true);

		$this->patcher->expects($this->once())
			->method('patchObject')
			->with('blog', 'my-post', $this->callback(static fn (array $data): bool => $data['id'] === 'my-post'))
			->willReturn($this->blogObject('my-post'));

		$this->tool->patchHandler(collection: 'blog', id: 'my-post', data: ['id' => 'other-post', 'title' => 'X']);
	}

	public function testPatchRefusesWhenPayloadSetsBinaryField(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'        => ['field' => 'id'],
				'thumbnail' => ['field' => 'image'],
			]));

		$this->patcher->expects($this->never())->method('patchObject');

		try {
			$this->tool->patchHandler(collection: 'blog', id: 'home', data: ['thumbnail' => 'new.jpg']);
			$this->fail('Expected ToolCallException for binary write.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('thumbnail', $e->getMessage());
		}
	}

	public function testPatchStripsEmptyBinaryEchoSoMergeCannotClearIt(): void
	{
		// A blank binary echo (thumbnail: "") merged over the stored object
		// would CLEAR the existing image — update semantics preserve it, so
		// patch must strip the key before the merge.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'        => ['field' => 'id'],
				'title'     => ['field' => 'text'],
				'thumbnail' => ['field' => 'image'],
			]));
		$this->objectFetcher->method('existsObject')->willReturn(true);

		$this->patcher->expects($this->once())
			->method('patchObject')
			->with('blog', 'home', $this->callback(static fn (array $data): bool => !array_key_exists('thumbnail', $data)))
			->willReturn($this->blogObject('home'));

		$this->tool->patchHandler(collection: 'blog', id: 'home', data: ['title' => 'New', 'thumbnail' => '']);
	}

	public function testPatchMissingObjectSurfacesCreateHint(): void
	{
		// Patch on a nonexistent object should not fall through to the merge
		// (array_merge over a fetch failure) — refuse with pointers at the
		// discovery and creation tools.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->textOnlySchema());
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$this->patcher->expects($this->never())->method('patchObject');

		try {
			$this->tool->patchHandler(collection: 'blog', id: 'ghost', data: ['title' => 'X']);
			$this->fail('Expected ToolCallException for missing object.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('ghost', $e->getMessage());
			$this->assertStringContainsString('create_object', $e->getMessage());
		}
	}

	public function testPatchConvertsDomainErrorsToToolErrors(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->textOnlySchema());
		$this->objectFetcher->method('existsObject')->willReturn(true);

		$this->patcher->method('patchObject')
			->willThrowException(new \DomainException('Schema Validation Failed. (/title) too long'));

		try {
			$this->tool->patchHandler(collection: 'blog', id: 'my-post', data: ['title' => 'X']);
			$this->fail('Expected ToolCallException.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blog/my-post', $e->getMessage());
			$this->assertStringContainsString('Schema Validation Failed', $e->getMessage());
		}
	}

	public function testPatchIsAnnotatedIdempotentAndNonDestructive(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$ann = $registry->get('patch_object')->annotations;

		$this->assertNotNull($ann);
		$this->assertTrue($ann->idempotentHint);
		$this->assertFalse($ann->destructiveHint);
		$this->assertFalse($ann->readOnlyHint);
	}

	// ─── Exposure gate (final review fix, Critical #1) ───────────────────────
	//
	// McpServerFactory::guardHandler() only checks scope + access-group grant
	// for objects/create and objects/update — it has no exposure carve-out to
	// apply the way objects/read does. Each handler now calls requireExposed()
	// itself before doing anything else, so a caller with a real group grant
	// still can't write into a collection the operator never turned on for
	// MCP. Feature-level coverage (real scope+group+exposure interplay over
	// HTTP) lives in tests/Feature/McpAuthenticatedPersonaTest.php Scenario
	// 18; these are the narrow unit-level checks that requireExposed() itself
	// is wired into all three handlers and fails closed.

	public function testCreateThrowsWhenCollectionExposureDeniesTheCaller(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->textOnlySchema());
		$this->exposureAccessible = false;
		$this->saver->expects($this->never())->method('saveObject');

		try {
			$this->tool->createHandler(collection: 'blog', data: ['title' => 'Nope']);
			$this->fail('Expected ToolCallException for exposure denial.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blog', $e->getMessage());
			$this->assertStringContainsString('not available', $e->getMessage());
		}
	}

	public function testUpdateThrowsWhenCollectionExposureDeniesTheCaller(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->textOnlySchema());
		$this->exposureAccessible = false;
		$this->updater->expects($this->never())->method('updateObject');

		try {
			$this->tool->updateHandler(collection: 'blog', id: 'my-post', data: ['title' => 'Nope']);
			$this->fail('Expected ToolCallException for exposure denial.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('not available', $e->getMessage());
		}
	}

	public function testPatchThrowsWhenCollectionExposureDeniesTheCaller(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->textOnlySchema());
		$this->exposureAccessible = false;
		$this->patcher->expects($this->never())->method('patchObject');
		// The exposure check runs before the existence check — a caller
		// without exposure gets the same opaque denial whether or not the
		// object actually exists.
		$this->objectFetcher->expects($this->never())->method('existsObject');

		try {
			$this->tool->patchHandler(collection: 'blog', id: 'my-post', data: ['title' => 'Nope']);
			$this->fail('Expected ToolCallException for exposure denial.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('not available', $e->getMessage());
		}
	}

	public function testCreateThrowsDiscoveryHintWhenCollectionFetcherFindsNothing(): void
	{
		// Defensive branch: schemaFetcher and collectionFetcher are separate
		// lookups. If a schema somehow resolves but the collection itself
		// doesn't, requireExposed() must still fail closed with a discovery
		// hint rather than passing a null CollectionData downstream.
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->textOnlySchema());
		$this->collectionExists = false;
		$this->saver->expects($this->never())->method('saveObject');

		try {
			$this->tool->createHandler(collection: 'blog', data: ['title' => 'Nope']);
			$this->fail('Expected ToolCallException for missing collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blog', $e->getMessage());
			$this->assertStringContainsString('not found', $e->getMessage());
		}
	}

	// ─── Non-exposed property stripping (final review fix, Critical #1b) ────

	public function testCreateStripsNonExposedPropertyFromReturnedObject(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->textOnlySchema());
		$this->hiddenProperties = ['secret'];
		$this->saver->method('saveObject')
			->willReturn($this->objectWith('my-post', ['title' => 'Visible', 'secret' => 'hidden-value']));

		$result = $this->tool->createHandler(collection: 'blog', data: ['title' => 'Visible']);

		$this->assertSame(['id' => 'my-post', 'title' => 'Visible'], $result);
		$this->assertArrayNotHasKey('secret', $result);
	}

	public function testUpdateStripsNonExposedPropertyFromReturnedObject(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->textOnlySchema());
		$this->hiddenProperties = ['secret'];
		$this->updater->method('updateObject')
			->willReturn($this->objectWith('my-post', ['title' => 'Visible', 'secret' => 'hidden-value']));

		$result = $this->tool->updateHandler(collection: 'blog', id: 'my-post', data: ['title' => 'Visible']);

		$this->assertArrayNotHasKey('secret', $result);
	}

	public function testPatchStripsNonExposedPropertyFromReturnedObject(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->textOnlySchema());
		$this->hiddenProperties = ['secret'];
		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->patcher->method('patchObject')
			->willReturn($this->objectWith('my-post', ['title' => 'Visible', 'secret' => 'hidden-value']));

		$result = $this->tool->patchHandler(collection: 'blog', id: 'my-post', data: ['title' => 'Visible']);

		$this->assertArrayNotHasKey('secret', $result);
	}
}

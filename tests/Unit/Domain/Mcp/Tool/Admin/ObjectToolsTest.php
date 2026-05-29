<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Admin;

use Illuminate\Support\Collection;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Tool\Admin\ObjectTools;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class ObjectToolsTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $saver;
	private \PHPUnit\Framework\MockObject\MockObject $updater;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private ObjectTools $tool;

	protected function setUp(): void
	{
		$this->saver         = $this->createMock(ObjectSaver::class);
		$this->updater       = $this->createMock(ObjectUpdater::class);
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->tool          = new ObjectTools($this->saver, $this->updater, $this->schemaFetcher);
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

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsBothToolsWithAdminAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$create = $registry->get('create_object');
		$update = $registry->get('update_object');

		$this->assertNotNull($create);
		$this->assertNotNull($update);
		$this->assertSame('admin', $create->access);
		$this->assertSame('admin', $update->access);
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

	// ─── create_object refusals ──────────────────────────────────────────────

	public function testCreateRefusesCollectionsWithImageFields(): void
	{
		// builder-page schema has a top-level image field. The tool must
		// refuse upfront with the field name in the error so the agent
		// knows which field is blocking the write.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'        => ['field' => 'id'],
				'title'     => ['field' => 'text'],
				'thumbnail' => ['field' => 'image'],
			]));

		$this->saver->expects($this->never())->method('saveObject');

		try {
			$this->tool->createHandler(collection: 'builder-pages', data: ['title' => 'Home']);
			$this->fail('Expected ToolCallException for image field.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('thumbnail', $e->getMessage());
			$this->assertStringContainsString('image', $e->getMessage());
		}
	}

	public function testCreateRefusesCollectionsWithMultipleBinaryFields(): void
	{
		// All offending fields appear in the same error — saves the agent a
		// retry round-trip discovering them one at a time.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'       => ['field' => 'id'],
				'cover'    => ['field' => 'image'],
				'attached' => ['field' => 'file'],
				'pics'     => ['field' => 'gallery'],
			]));

		try {
			$this->tool->createHandler(collection: 'asset-heavy', data: []);
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

	public function testUpdateRefusesCollectionsWithBinaryFields(): void
	{
		// Same refusal as create — update mode doesn't change the binary
		// constraint.
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->schema([
				'id'        => ['field' => 'id'],
				'thumbnail' => ['field' => 'image'],
			]));

		$this->updater->expects($this->never())->method('updateObject');

		$this->expectException(ToolCallException::class);
		$this->tool->updateHandler(collection: 'builder-pages', id: 'home', data: ['title' => 'Home']);
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
}

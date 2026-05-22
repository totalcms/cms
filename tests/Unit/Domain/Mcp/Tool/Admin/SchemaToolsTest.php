<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Tool\Admin\SchemaTools;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaRemover;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

final class SchemaToolsTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $lister;
	private \PHPUnit\Framework\MockObject\MockObject $fetcher;
	private \PHPUnit\Framework\MockObject\MockObject $saver;
	private \PHPUnit\Framework\MockObject\MockObject $remover;
	private SchemaTools $tool;

	protected function setUp(): void
	{
		$this->lister  = $this->createMock(SchemaLister::class);
		$this->fetcher = $this->createMock(SchemaFetcher::class);
		$this->saver   = $this->createMock(SchemaSaver::class);
		$this->remover = $this->createMock(SchemaRemover::class);

		$this->tool = new SchemaTools(
			$this->lister,
			$this->fetcher,
			$this->saver,
			$this->remover,
		);
	}

	private function schema(string $id, array $properties = []): SchemaData
	{
		$schema              = new SchemaData();
		$schema->id          = $id;
		$schema->description = "Schema $id";
		$schema->properties  = $properties;

		return $schema;
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsAllFiveSchemaToolsWithAdminAccess(): void
	{
		// One class registers a family of related tools — list_schemas/get/create
		// /update/delete. All admin (write-side is sensitive; even the reads
		// here are operator-grade — agent fetching a schema definition is doing
		// dev work, not content browsing).
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		foreach (['list_schemas', 'get_schema', 'create_schema', 'update_schema', 'delete_schema'] as $name) {
			$definition = $registry->get($name);
			$this->assertNotNull($definition, "Tool $name should be registered");
			$this->assertSame('admin', $definition->access, "Tool $name should be admin-only");
		}
	}

	public function testReadToolsAreMarkedReadOnlyIdempotent(): void
	{
		// Anthropic Directory review: read vs write tools must declare their
		// nature via ToolAnnotations. list_schemas / get_schema are pure reads.
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		foreach (['list_schemas', 'get_schema'] as $name) {
			$ann = $registry->get($name)->annotations;
			$this->assertNotNull($ann, "Tool $name needs annotations");
			$this->assertTrue($ann->readOnlyHint, "Tool $name should be readOnly");
			$this->assertTrue($ann->idempotentHint, "Tool $name should be idempotent");
		}
	}

	public function testCreateAnnotatedAsWriteNonDestructive(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$ann = $registry->get('create_schema')->annotations;
		$this->assertNotNull($ann);
		$this->assertFalse($ann->readOnlyHint);
		$this->assertFalse($ann->destructiveHint);
		// Creating "the same" schema twice errors on collision — not idempotent.
		$this->assertFalse($ann->idempotentHint);
	}

	public function testUpdateAnnotatedAsWriteIdempotent(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$ann = $registry->get('update_schema')->annotations;
		$this->assertNotNull($ann);
		$this->assertFalse($ann->readOnlyHint);
		$this->assertFalse($ann->destructiveHint);
		// Applying the same update twice yields the same state — idempotent.
		$this->assertTrue($ann->idempotentHint);
	}

	public function testDeleteAnnotatedAsDestructive(): void
	{
		// The critical case. Anthropic Directory review treats destructiveHint
		// as load-bearing — hosts surface destructive tools differently in UI.
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$ann = $registry->get('delete_schema')->annotations;
		$this->assertNotNull($ann);
		$this->assertFalse($ann->readOnlyHint);
		$this->assertTrue($ann->destructiveHint);
		$this->assertFalse($ann->idempotentHint);
	}

	// ─── list_schemas ─────────────────────────────────────────────────────────

	public function testListReturnsAllSchemasWithMetadata(): void
	{
		$this->lister->method('listAllSchemas')->willReturn([
			$this->schema('blog'),
			$this->schema('products'),
		]);

		$result = $this->tool->listHandler();

		$this->assertCount(2, $result['schemas']);
		$this->assertSame('blog', $result['schemas'][0]['id']);
		$this->assertSame('Schema blog', $result['schemas'][0]['description']);
		$this->assertSame(2, $result['total']);
	}

	// ─── get_schema ──────────────────────────────────────────────────────────

	public function testGetReturnsFullSchemaAsJsonShape(): void
	{
		$schema = $this->schema('blog', [
			'title' => ['field' => 'text', 'label' => 'Title'],
		]);
		$this->fetcher->method('fetchSchema')->with('blog')->willReturn($schema);

		$result = $this->tool->getHandler(id: 'blog');

		// Result should be the SchemaData::toArray() shape — full JSON schema
		// the operator could paste into a schema file.
		$this->assertSame('blog', $result['id']);
		$this->assertArrayHasKey('properties', $result);
		$this->assertSame(['field' => 'text', 'label' => 'Title'], $result['properties']['title']);
	}

	public function testGetUnknownSchemaThrowsRecoveryHint(): void
	{
		// fetchSchema throws \UnexpectedValueException for a missing schema.
		// We must convert to ToolCallException with a hint at list_schemas.
		$this->fetcher->method('fetchSchema')
			->willThrowException(new \UnexpectedValueException('no such schema'));

		try {
			$this->tool->getHandler(id: 'nonexistent');
			$this->fail('Expected ToolCallException for missing schema.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('nonexistent', $e->getMessage());
			$this->assertStringContainsString('list_schemas', $e->getMessage());
		}
	}

	// ─── create_schema ───────────────────────────────────────────────────────

	public function testCreateDispatchesToSaverWithGivenShape(): void
	{
		$saved = $this->schema('portfolio', [
			'title' => ['field' => 'text'],
		]);
		$this->saver->expects($this->once())
			->method('saveSchema')
			->with($this->callback(static fn (array $data): bool => $data['id'] === 'portfolio'
					&& isset($data['properties']['title'])))
			->willReturn($saved);

		$result = $this->tool->createHandler(
			id: 'portfolio',
			properties: ['title' => ['field' => 'text']],
		);

		$this->assertSame('portfolio', $result['id']);
	}

	public function testCreateConvertsSaverErrorsToRecoveryHints(): void
	{
		// SchemaSaver::saveSchema throws \UnexpectedValueException for reserved
		// ids and \InvalidArgumentException for bad shapes. Both must surface
		// as ToolCallException so the SDK wraps them as isError:true.
		$this->saver->method('saveSchema')
			->willThrowException(new \UnexpectedValueException('Schema type (blog) is reserved'));

		try {
			$this->tool->createHandler(id: 'blog', properties: ['title' => ['field' => 'text']]);
			$this->fail('Expected ToolCallException for reserved id.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('reserved', strtolower($e->getMessage()));
		}
	}

	// ─── update_schema ───────────────────────────────────────────────────────

	public function testUpdateDispatchesToSaver(): void
	{
		$updated = $this->schema('blog', ['title' => ['field' => 'text']]);
		$this->saver->expects($this->once())
			->method('updateSchema')
			->with('blog', $this->anything())
			->willReturn($updated);

		$result = $this->tool->updateHandler(
			id: 'blog',
			properties: ['title' => ['field' => 'text']],
		);

		$this->assertSame('blog', $result['id']);
	}

	public function testUpdateMismatchedIdConvertedToRecoveryHint(): void
	{
		$this->saver->method('updateSchema')
			->willThrowException(new \UnexpectedValueException('Schema ID does not match'));

		try {
			$this->tool->updateHandler(id: 'blog', properties: ['title' => ['field' => 'text']]);
			$this->fail('Expected ToolCallException for id mismatch.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blog', $e->getMessage());
		}
	}

	// ─── delete_schema ───────────────────────────────────────────────────────

	public function testDeleteDispatchesToRemoverAndReturnsConfirmation(): void
	{
		$this->remover->expects($this->once())
			->method('deleteSchema')
			->with('portfolio')
			->willReturn(true);

		$result = $this->tool->deleteHandler(id: 'portfolio');

		$this->assertTrue($result['deleted']);
		$this->assertSame('portfolio', $result['id']);
	}

	public function testDeleteRejectsReservedSchemaWithRecoveryHint(): void
	{
		// SchemaRemover throws \UnexpectedValueException for reserved schemas.
		$this->remover->method('deleteSchema')
			->willThrowException(new \UnexpectedValueException('Unable to delete schema type (blog) is reserved'));

		try {
			$this->tool->deleteHandler(id: 'blog');
			$this->fail('Expected ToolCallException for reserved schema.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('reserved', strtolower($e->getMessage()));
		}
	}

	public function testDeleteRejectsInheritedSchemaWithRecoveryHint(): void
	{
		// SchemaRemover throws \DomainException when the schema is inherited
		// or used by a collection — distinct exception type, same UX outcome.
		$this->remover->method('deleteSchema')
			->willThrowException(new \DomainException('Unable to delete schema (custom). It is inherited by: child'));

		try {
			$this->tool->deleteHandler(id: 'custom');
			$this->fail('Expected ToolCallException for inherited schema.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('inherited', strtolower($e->getMessage()));
		}
	}
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Mcp\Tool\Admin\CollectionTools;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

final class CollectionToolsTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $saver;
	private CollectionTools $tool;

	protected function setUp(): void
	{
		$this->saver = $this->createMock(CollectionSaver::class);
		$this->tool  = new CollectionTools($this->saver);
	}

	private function collection(string $id, string $schema = 'blog'): CollectionData
	{
		$c         = new CollectionData();
		$c->id     = $id;
		$c->schema = $schema;
		$c->mcp    = ['access' => 'admin'];

		return $c;
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsCreateCollectionWithAdminAccess(): void
	{
		// Listing is handled by the public list_collections tool (admin sees
		// everything through it). This class is just the create surface today.
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('create_collection');
		$this->assertNotNull($definition);
		$this->assertSame('admin', $definition->access);
	}

	/**
	 * Task 8 fix round, Important #4: pin the exact ToolRequirement triple —
	 * nothing previously asserted this, so a wrong domain string or flipped
	 * operation would silently deny-all and every other test would still pass.
	 */
	public function testCreateCollectionDeclaresTheExpectedRequirementTriple(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$requires = $registry->get('create_collection')->requires;
		$this->assertNotNull($requires);
		$this->assertSame('collections-meta', $requires->domain);
		$this->assertSame('create', $requires->operation);
		$this->assertSame('id', $requires->collectionArg);
	}

	public function testCreateCollectionAnnotatedAsWriteNonDestructive(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$ann = $registry->get('create_collection')->annotations;
		$this->assertNotNull($ann);
		$this->assertFalse($ann->readOnlyHint);
		$this->assertFalse($ann->destructiveHint);
		// Re-creating same id errors — not idempotent.
		$this->assertFalse($ann->idempotentHint);
	}

	// ─── create_collection ───────────────────────────────────────────────────

	public function testCreateDispatchesToSaverWithGivenShape(): void
	{
		$saved = $this->collection('reviews', 'review');
		$this->saver->expects($this->once())
			->method('saveCollection')
			->with($this->callback(static fn (array $data): bool => $data['id'] === 'reviews' && $data['schema'] === 'review'))
			->willReturn($saved);

		$result = $this->tool->createHandler(id: 'reviews', schema: 'review');

		$this->assertSame('reviews', $result['id']);
	}

	public function testCreatePassesOptionalExtraFieldsThrough(): void
	{
		$saved = $this->collection('reviews', 'review');
		$this->saver->expects($this->once())
			->method('saveCollection')
			->with($this->callback(static fn (array $data): bool => ($data['name'] ?? '') === 'Customer Reviews'
					&& ($data['url'] ?? '') === '/reviews/{id}'
					&& ($data['description'] ?? '') === 'Reviews from customers'))
			->willReturn($saved);

		$this->tool->createHandler(
			id: 'reviews',
			schema: 'review',
			extra: [
				'name'        => 'Customer Reviews',
				'url'         => '/reviews/{id}',
				'description' => 'Reviews from customers',
			],
		);
	}

	public function testCreateConvertsDuplicateIdToRecoveryHint(): void
	{
		// CollectionSaver throws \DomainException for "already exists". Should
		// surface as a tool error with the collision hint.
		$this->saver->method('saveCollection')
			->willThrowException(new \DomainException('Collection with id blog already exists'));

		try {
			$this->tool->createHandler(id: 'blog', schema: 'blog');
			$this->fail('Expected ToolCallException for duplicate collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blog', $e->getMessage());
			$this->assertStringContainsString('exists', strtolower($e->getMessage()));
		}
	}

	public function testCreateConvertsUnexpectedValueToRecoveryHint(): void
	{
		$this->saver->method('saveCollection')
			->willThrowException(new \UnexpectedValueException('Schema (nonsense) does not exist'));

		try {
			$this->tool->createHandler(id: 'reviews', schema: 'nonsense');
			$this->fail('Expected ToolCallException.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('nonsense', $e->getMessage());
		}
	}
}

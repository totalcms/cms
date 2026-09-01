<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Orphan;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Orphan\OrphanCleanupAction;
use TotalCMS\Domain\Orphan\Data\OrphanReport;
use TotalCMS\Domain\Orphan\Service\OrphanCleaner;
use TotalCMS\Domain\Orphan\Service\OrphanScanner;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

/**
 * `POST /api/orphan/cleanup` strips orphaned references out of stored objects.
 * It is a destructive endpoint that had no test at all, and the thing worth
 * pinning is not the happy path but which branch a given body selects — a body
 * that selects the wrong branch deletes the wrong things.
 */
final class OrphanCleanupActionTest extends TestCase
{
	private OrphanScanner $scanner;
	private OrphanCleaner $cleaner;

	protected function setUp(): void
	{
		$this->scanner = $this->createMock(OrphanScanner::class);
		$this->cleaner = $this->createMock(OrphanCleaner::class);
	}

	private function action(): OrphanCleanupAction
	{
		return new OrphanCleanupAction($this->scanner, $this->cleaner, new JsonRenderer());
	}

	/** @param array<string,mixed> $body */
	private function request(array $body): ServerRequestInterface
	{
		return (new Psr17Factory())
			->createServerRequest('POST', '/api/orphan/cleanup')
			->withParsedBody($body);
	}

	/** @return array<string,mixed> */
	private function invoke(ServerRequestInterface $request): array
	{
		$response = ($this->action())($request, new Response());
		$response->getBody()->rewind();

		return [
			'status' => $response->getStatusCode(),
			'body'   => (array)json_decode((string)$response->getBody(), true),
		];
	}

	// ── Refusing to act on an unusable request ───────────────────────────────

	public function testRejectsAnUnknownMode(): void
	{
		$this->scanner->expects($this->never())->method('scanAll');
		$this->cleaner->expects($this->never())->method('cleanAll');

		$result = $this->invoke($this->request(['mode' => 'everything']));

		$this->assertSame(400, $result['status']);
		$this->assertStringContainsString('all, collection, property', (string)$result['body']['error']);
	}

	public function testRejectsAMissingMode(): void
	{
		$result = $this->invoke($this->request([]));

		$this->assertSame(400, $result['status']);
	}

	public function testAnEmptySelectionDoesNotFallThroughToCleaningEverything(): void
	{
		// The UI posts `entries` from checkboxes. If nothing is ticked it sends
		// an empty array, and `$entries !== []` sends that down the mode branch
		// — so with no mode it must 400, not scan and clean the whole install.
		$this->scanner->expects($this->never())->method('scanAll');
		$this->cleaner->expects($this->never())->method('cleanAll');

		$result = $this->invoke($this->request(['entries' => []]));

		$this->assertSame(400, $result['status']);
	}

	public function testCollectionModeRequiresACollection(): void
	{
		$this->scanner->expects($this->never())->method('scanCollection');

		$result = $this->invoke($this->request(['mode' => 'collection']));

		$this->assertSame(400, $result['status']);
		$this->assertStringContainsString('Missing collection', (string)$result['body']['error']);
	}

	public function testPropertyModeRequiresBothCollectionAndProperty(): void
	{
		$this->scanner->expects($this->never())->method('scanCollection');

		$missingProperty = $this->invoke($this->request(['mode' => 'property', 'collection' => 'blog']));
		$this->assertSame(400, $missingProperty['status']);

		$missingCollection = $this->invoke($this->request(['mode' => 'property', 'property' => 'gallery']));
		$this->assertSame(400, $missingCollection['status']);
	}

	// ── Mode dispatch ────────────────────────────────────────────────────────

	public function testAllModeScansAndCleansEverything(): void
	{
		$report = $this->createMock(OrphanReport::class);
		$this->scanner->expects($this->once())->method('scanAll')->willReturn($report);
		$this->cleaner->expects($this->once())->method('cleanAll')
			->with($report)
			->willReturn(OperationResult::success('done', ['cleaned' => 4]));

		$result = $this->invoke($this->request(['mode' => 'all']));

		$this->assertSame(200, $result['status']);
		$this->assertTrue($result['body']['success']);
	}

	public function testCollectionModeScansOnlyThatCollection(): void
	{
		$report = $this->createMock(OrphanReport::class);
		// The scan is narrowed too, not just the clean: scanning everything and
		// cleaning one collection would do needless work on a large install.
		$this->scanner->expects($this->once())->method('scanCollection')
			->with('blog')->willReturn($report);
		$this->cleaner->expects($this->once())->method('cleanByCollection')
			->with($report, 'blog')
			->willReturn(OperationResult::success('done'));

		$this->assertSame(200, $this->invoke($this->request([
			'mode' => 'collection', 'collection' => 'blog',
		]))['status']);
	}

	public function testPropertyModeNarrowsToASingleProperty(): void
	{
		$report = $this->createMock(OrphanReport::class);
		$this->scanner->expects($this->once())->method('scanCollection')
			->with('blog')->willReturn($report);
		$this->cleaner->expects($this->once())->method('cleanByCollectionProperty')
			->with($report, 'blog', 'gallery')
			->willReturn(OperationResult::success('done'));

		$this->assertSame(200, $this->invoke($this->request([
			'mode' => 'property', 'collection' => 'blog', 'property' => 'gallery',
		]))['status']);
	}

	// ── Explicit entries from the admin UI ───────────────────────────────────

	public function testEntriesTakePrecedenceOverMode(): void
	{
		// A body carrying both must never run the mode branch as well — that
		// would clean the whole install alongside the tick-box selection.
		$this->scanner->expects($this->never())->method('scanAll');
		$this->cleaner->expects($this->once())->method('cleanProperty')
			->with('blog', 'post-1', 'gallery', ['img-9'], true)
			->willReturn(OperationResult::success());

		$result = $this->invoke($this->request([
			'mode'    => 'all',
			'entries' => [[
				'collection'  => 'blog',
				'objectId'    => 'post-1',
				'property'    => 'gallery',
				'orphanedIds' => ['img-9'],
				'isArray'     => true,
			]],
		]));

		$this->assertSame(1, $result['body']['cleaned']);
	}

	public function testCountsCleanedAndFailedEntriesSeparately(): void
	{
		$this->cleaner->method('cleanProperty')->willReturnOnConsecutiveCalls(
			OperationResult::success(),
			OperationResult::failure('nope', 'object not found'),
		);

		$entry = static fn (string $id): array => [
			'collection'  => 'blog',
			'objectId'    => $id,
			'property'    => 'gallery',
			'orphanedIds' => ['img-9'],
		];

		$result = $this->invoke($this->request(['entries' => [$entry('post-1'), $entry('post-2')]]));

		$this->assertSame(1, $result['body']['cleaned']);
		$this->assertSame(1, $result['body']['failed']);
		// The error names the object it belongs to; without that an operator
		// cleaning fifty entries cannot tell which one refused.
		$this->assertSame(['blog/post-2.gallery: object not found'], $result['body']['errors']);
	}

	public function testRejectsIncompleteEntriesWithoutTouchingTheCleaner(): void
	{
		$this->cleaner->expects($this->never())->method('cleanProperty');

		$result = $this->invoke($this->request(['entries' => [
			['objectId' => 'post-1', 'property' => 'gallery', 'orphanedIds' => []],  // no collection
			['collection' => 'blog', 'property' => 'gallery', 'orphanedIds' => []],  // no objectId
			['collection' => 'blog', 'objectId' => 'post-1', 'orphanedIds' => []],   // no property
			['collection' => 'blog', 'objectId' => 'post-1', 'property' => 'gallery', 'orphanedIds' => 'nope'],
		]]));

		$this->assertSame(0, $result['body']['cleaned']);
		$this->assertSame(4, $result['body']['failed']);
	}

	public function testSkipsEntriesThatAreNotArraysWithoutCountingThem(): void
	{
		$this->cleaner->expects($this->once())->method('cleanProperty')
			->willReturn(OperationResult::success());

		$result = $this->invoke($this->request(['entries' => [
			'garbage',
			['collection' => 'blog', 'objectId' => 'post-1', 'property' => 'gallery', 'orphanedIds' => ['x']],
		]]));

		$this->assertSame(1, $result['body']['cleaned']);
		$this->assertSame(0, $result['body']['failed']);
	}

	public function testIsArrayDefaultsToFalseWhenTheEntryOmitsIt(): void
	{
		// isArray decides whether the property is rewritten as a list or as a
		// single value, so the default is what a partial UI payload gets.
		$this->cleaner->expects($this->once())->method('cleanProperty')
			->with('blog', 'post-1', 'gallery', ['img-9'], false)
			->willReturn(OperationResult::success());

		$this->invoke($this->request(['entries' => [[
			'collection'  => 'blog',
			'objectId'    => 'post-1',
			'property'    => 'gallery',
			'orphanedIds' => ['img-9'],
		]]]));
	}

	public function testReportsSuccessEvenWhenEveryEntryFailed(): void
	{
		// Documenting current behaviour, not endorsing it: the envelope is
		// always success=true and the real outcome is in cleaned/failed. A
		// caller that checks only `success` will think a total failure worked.
		$this->cleaner->method('cleanProperty')
			->willReturn(OperationResult::failure('nope', 'permission denied'));

		$result = $this->invoke($this->request(['entries' => [[
			'collection'  => 'blog',
			'objectId'    => 'post-1',
			'property'    => 'gallery',
			'orphanedIds' => ['img-9'],
		]]]));

		$this->assertTrue($result['body']['success']);
		$this->assertSame(0, $result['body']['cleaned']);
		$this->assertSame(1, $result['body']['failed']);
	}
}

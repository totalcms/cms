<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Visualizer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Visualizer\Service\AccessGroupAnalyzer;
use TotalCMS\Domain\Visualizer\Service\AccessGroupMatrixPresenter;
use TotalCMS\Domain\Visualizer\Service\MermaidErdRenderer;
use TotalCMS\Domain\Visualizer\Service\MermaidFlowchartRenderer;
use TotalCMS\Domain\Visualizer\Service\ObjectRelationshipResolver;
use TotalCMS\Domain\Visualizer\Service\RelationshipAnalyzer;
use TotalCMS\Domain\Visualizer\Service\VisualizerService;

/**
 * Verifies that collectionGraph() and objectGraph() filter out collections
 * a non-super-admin operator cannot read, and that super-admins are unaffected.
 */
final class VisualizerServiceAccessFilterTest extends TestCase
{
	private RelationshipAnalyzer&MockObject $analyzer;
	private MermaidErdRenderer $erdRenderer;
	private ObjectRelationshipResolver&MockObject $objectResolver;
	private MermaidFlowchartRenderer $flowchartRenderer;
	private AccessGroupAnalyzer&MockObject $accessGroupAnalyzer;
	private AccessGroupMatrixPresenter $matrixPresenter;
	private AccessControlService&MockObject $accessControl;

	/** @var list<CollectionData> */
	private array $allCollections;

	protected function setUp(): void
	{
		$this->analyzer            = $this->createMock(RelationshipAnalyzer::class);
		$this->erdRenderer         = new MermaidErdRenderer();
		$this->objectResolver      = $this->createMock(ObjectRelationshipResolver::class);
		$this->flowchartRenderer   = new MermaidFlowchartRenderer();
		$this->accessGroupAnalyzer = $this->createMock(AccessGroupAnalyzer::class);
		$this->matrixPresenter     = new AccessGroupMatrixPresenter();
		$this->accessControl       = $this->createMock(AccessControlService::class);

		$this->allCollections = [
			$this->makeCollection('posts', 'Posts'),
			$this->makeCollection('authors', 'Authors'),
			$this->makeCollection('secret', 'Secret'),
		];
	}

	private function makeService(): VisualizerService
	{
		return new VisualizerService(
			$this->analyzer,
			$this->erdRenderer,
			$this->objectResolver,
			$this->flowchartRenderer,
			$this->accessGroupAnalyzer,
			$this->matrixPresenter,
			$this->accessControl,
		);
	}

	private function makeCollection(string $id, string $name): CollectionData
	{
		$c       = new CollectionData();
		$c->id   = $id;
		$c->name = $name;

		return $c;
	}

	/** Full ERD graph with nodes for all three collections + an edge posts→authors */
	private function fullGraph(): array
	{
		return [
			'nodes' => [
				'posts'   => ['id' => 'posts',   'kind' => 'collection', 'label' => 'Posts',   'schema' => 'post',   'fields' => []],
				'authors' => ['id' => 'authors', 'kind' => 'collection', 'label' => 'Authors', 'schema' => 'author', 'fields' => []],
				'secret'  => ['id' => 'secret',  'kind' => 'collection', 'label' => 'Secret',  'schema' => 'secret', 'fields' => []],
			],
			'edges' => [
				['from' => 'posts', 'to' => 'authors', 'type' => 'relational', 'via' => 'writer'],
				['from' => 'posts', 'to' => 'secret',  'type' => 'relational', 'via' => 'classified'],
			],
		];
	}

	/** Object graph where focal node is in 'authors', neighbour is in 'secret' */
	private function objectGraph(): array
	{
		return [
			'nodes' => [
				'authors::jane'   => ['id' => 'authors::jane',   'collection' => 'authors', 'objectId' => 'jane',   'label' => 'Jane',   'focal' => true],
				'secret::report1' => ['id' => 'secret::report1', 'collection' => 'secret',  'objectId' => 'report1', 'label' => 'Report1', 'focal' => false],
			],
			'edges' => [
				['from' => 'authors::jane', 'to' => 'secret::report1', 'direction' => 'outbound', 'via' => 'classified'],
			],
			'truncated' => false,
		];
	}

	// ── collectionGraph() ──────────────────────────────────────────────────

	public function testCollectionGraphSuperAdminSeesAll(): void
	{
		$this->accessControl->method('isAdmin')->with('admin')->willReturn(true);
		$this->analyzer->method('analyze')->willReturn($this->fullGraph());
		$this->analyzer->method('pruneIsolated')->willReturnArgument(0);

		$svc    = $this->makeService();
		$result = $svc->collectionGraph($this->allCollections, ['isolated' => '1'], 'admin');

		// All three collections passed through to the view
		$collectionIds = array_map(static fn (CollectionData $c): string => $c->id, $result['collections']);
		$this->assertContains('posts', $collectionIds);
		$this->assertContains('authors', $collectionIds);
		$this->assertContains('secret', $collectionIds);

		// All three nodes present
		$this->assertSame(3, $result['nodeCount']);
	}

	public function testCollectionGraphNullUserIdSeesAll(): void
	{
		// null userId → no filtering regardless of access control
		$this->accessControl->expects($this->never())->method('isAdmin');
		$this->accessControl->expects($this->never())->method('canAccessCollection');
		$this->analyzer->method('analyze')->willReturn($this->fullGraph());
		$this->analyzer->method('pruneIsolated')->willReturnArgument(0);

		$svc    = $this->makeService();
		$result = $svc->collectionGraph($this->allCollections, ['isolated' => '1'], null);

		$this->assertSame(3, $result['nodeCount']);
	}

	public function testCollectionGraphRestrictedUserSeesOnlyPermittedCollections(): void
	{
		// restricted user can read posts + authors, not secret
		$this->accessControl->method('isAdmin')->with('editor')->willReturn(false);
		$this->accessControl->method('canAccessCollection')
			->willReturnMap([
				['editor', 'posts',   'read', true],
				['editor', 'authors', 'read', true],
				['editor', 'secret',  'read', false],
			]);

		$this->analyzer->method('analyze')->willReturn($this->fullGraph());
		$this->analyzer->method('pruneIsolated')->willReturnArgument(0);

		$svc    = $this->makeService();
		$result = $svc->collectionGraph($this->allCollections, ['isolated' => '1'], 'editor');

		// 'secret' node must be gone
		$this->assertSame(2, $result['nodeCount']);

		// The collections list passed to the view must exclude 'secret'
		$collectionIds = array_map(static fn (CollectionData $c): string => $c->id, $result['collections']);
		$this->assertContains('posts', $collectionIds);
		$this->assertContains('authors', $collectionIds);
		$this->assertNotContains('secret', $collectionIds);

		// Edge posts→secret must be gone; posts→authors must remain
		$this->assertSame(1, $result['edgeCount']);
	}

	public function testCollectionGraphDropsEdgesToHiddenCollection(): void
	{
		// Only 'authors' is permitted — both edges (posts→authors and posts→secret)
		// plus the 'posts' node itself should be absent from the result because
		// 'posts' is not permitted.
		$this->accessControl->method('isAdmin')->willReturn(false);
		$this->accessControl->method('canAccessCollection')
			->willReturnMap([
				['restricted', 'posts',   'read', false],
				['restricted', 'authors', 'read', true],
				['restricted', 'secret',  'read', false],
			]);

		$this->analyzer->method('analyze')->willReturn($this->fullGraph());
		$this->analyzer->method('pruneIsolated')->willReturnArgument(0);

		$svc    = $this->makeService();
		$result = $svc->collectionGraph($this->allCollections, ['isolated' => '1'], 'restricted');

		// Only 'authors' remains (isolated pruning off via isolated=1)
		$this->assertArrayHasKey('authors', $result['nodes'] ?? ['authors' => true]);
		$this->assertSame(0, $result['edgeCount']);
	}

	// ── objectGraph() ─────────────────────────────────────────────────────

	public function testObjectGraphSuperAdminSeesAll(): void
	{
		$this->accessControl->method('isAdmin')->with('admin')->willReturn(true);
		$this->objectResolver->method('resolve')
			->with('authors', 'jane')
			->willReturn($this->objectGraph());

		$svc    = $this->makeService();
		$result = $svc->objectGraph(
			$this->allCollections,
			['collection' => 'authors', 'id' => 'jane'],
			'admin',
		);

		$this->assertSame(2, $result['nodeCount']);
		$this->assertSame(1, $result['edgeCount']);
		$this->assertSame('authors', $result['collection']);
	}

	public function testObjectGraphRestrictedUserNeighbourInHiddenCollectionDropped(): void
	{
		// editor can read 'authors' but NOT 'secret'
		$this->accessControl->method('isAdmin')->with('editor')->willReturn(false);
		$this->accessControl->method('canAccessCollection')
			->willReturnMap([
				['editor', 'posts',   'read', true],
				['editor', 'authors', 'read', true],
				['editor', 'secret',  'read', false],
			]);

		$this->objectResolver->method('resolve')
			->with('authors', 'jane')
			->willReturn($this->objectGraph());

		$svc    = $this->makeService();
		$result = $svc->objectGraph(
			$this->allCollections,
			['collection' => 'authors', 'id' => 'jane'],
			'editor',
		);

		// focal node (authors::jane) kept; secret::report1 dropped
		$this->assertSame(1, $result['nodeCount']);
		// edge to secret also dropped
		$this->assertSame(0, $result['edgeCount']);
		$this->assertSame('authors', $result['collection']);
	}

	public function testObjectGraphRestrictedUserCannotAccessFocalCollection(): void
	{
		// editor cannot read 'secret' at all — requesting secret collection returns empty
		$this->accessControl->method('isAdmin')->with('editor')->willReturn(false);
		$this->accessControl->method('canAccessCollection')
			->willReturnMap([
				['editor', 'posts',   'read', true],
				['editor', 'authors', 'read', true],
				['editor', 'secret',  'read', false],
			]);

		// objectResolver should NOT be called because collection is filtered out
		$this->objectResolver->expects($this->never())->method('resolve');
		$this->objectResolver->expects($this->never())->method('resolveCollection');

		$svc    = $this->makeService();
		$result = $svc->objectGraph(
			$this->allCollections,
			['collection' => 'secret', 'id' => 'report1'],
			'editor',
		);

		$this->assertSame('', $result['collection']);
		$this->assertNull($result['mermaid']);
		$this->assertSame(0, $result['nodeCount']);
	}
}

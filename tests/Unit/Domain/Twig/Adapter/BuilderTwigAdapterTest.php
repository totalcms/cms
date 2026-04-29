<?php

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Twig\Adapter\BuilderTwigAdapter;

final class BuilderTwigAdapterTest extends TestCase
{
	private BuilderTwigAdapter $adapter;
	private \PHPUnit\Framework\MockObject\MockObject $builderConfig;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;

	protected function setUp(): void
	{
		$this->builderConfig = $this->createMock(BuilderConfigService::class);
		$this->indexReader   = $this->createMock(IndexReader::class);

		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');

		$this->adapter = new BuilderTwigAdapter(
			$this->builderConfig,
			$this->indexReader,
		);
	}

	private function setupPages(array $pages): void
	{
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($pages));
	}

	private function samplePages(): array
	{
		return [
			['id' => 'home',     'title' => 'Home',     'route' => '/',          'sort' => 0, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'about',    'title' => 'About',    'route' => '/about',     'sort' => 1, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'blog',     'title' => 'Blog',     'route' => '/blog',      'sort' => 2, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'blog-post','title' => 'Blog Post','route' => '/blog/{id}', 'sort' => 3, 'parent' => 'blog', 'draft' => false, 'nav' => false],
			['id' => 'contact',  'title' => 'Contact',  'route' => '/contact',   'sort' => 4, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'privacy',  'title' => 'Privacy',  'route' => '/privacy',   'sort' => 5, 'parent' => '',     'draft' => false, 'nav' => false],
			['id' => 'drafts',   'title' => 'Drafts',   'route' => '/drafts',    'sort' => 6, 'parent' => '',     'draft' => true,  'nav' => true],
		];
	}

	// --- nav() ---

	public function testNavReturnsTopLevelPages(): void
	{
		$this->setupPages($this->samplePages());

		$result = $this->adapter->nav();

		$ids = array_column($result, 'id');
		$this->assertSame(['home', 'about', 'blog', 'contact'], $ids);
	}

	public function testNavExcludesDraftPages(): void
	{
		$this->setupPages($this->samplePages());

		$result = $this->adapter->nav();

		$ids = array_column($result, 'id');
		$this->assertNotContains('drafts', $ids);
	}

	public function testNavExcludesNavFalsePages(): void
	{
		$this->setupPages($this->samplePages());

		$result = $this->adapter->nav();

		$ids = array_column($result, 'id');
		$this->assertNotContains('privacy', $ids);
	}

	public function testNavExcludesChildPages(): void
	{
		$this->setupPages($this->samplePages());

		$result = $this->adapter->nav();

		$ids = array_column($result, 'id');
		$this->assertNotContains('blog-post', $ids);
	}

	public function testNavSortsBySortField(): void
	{
		$this->setupPages([
			['id' => 'c', 'title' => 'C', 'sort' => 2, 'parent' => '', 'draft' => false, 'nav' => true],
			['id' => 'a', 'title' => 'A', 'sort' => 0, 'parent' => '', 'draft' => false, 'nav' => true],
			['id' => 'b', 'title' => 'B', 'sort' => 1, 'parent' => '', 'draft' => false, 'nav' => true],
		]);

		$result = $this->adapter->nav();

		$ids = array_column($result, 'id');
		$this->assertSame(['a', 'b', 'c'], $ids);
	}

	public function testNavReturnsEmptyForNoPages(): void
	{
		$this->setupPages([]);

		$result = $this->adapter->nav();

		$this->assertSame([], $result);
	}

	public function testNavReturnsEmptyWhenCollectionDoesNotExist(): void
	{
		$this->indexReader->method('fetchIndex')->willThrowException(new \Exception('not found'));

		$result = $this->adapter->nav();

		$this->assertSame([], $result);
	}

	public function testNavUsesCustomCollection(): void
	{
		$this->indexReader->expects($this->once())
			->method('fetchIndex')
			->with('custom-pages')
			->willReturn(new IndexData([
				['id' => 'page1', 'title' => 'Page 1', 'sort' => 0, 'parent' => '', 'draft' => false, 'nav' => true],
			]));

		$result = $this->adapter->nav('custom-pages');

		$this->assertCount(1, $result);
		$this->assertSame('page1', $result[0]['id']);
	}

	public function testNavTreatsMissingNavFieldAsTrue(): void
	{
		$this->setupPages([
			['id' => 'old-page', 'title' => 'Old Page', 'sort' => 0, 'parent' => '', 'draft' => false],
		]);

		$result = $this->adapter->nav();

		$this->assertCount(1, $result);
		$this->assertSame('old-page', $result[0]['id']);
	}

	// --- subnav() ---

	public function testSubnavReturnsChildrenOfParent(): void
	{
		$this->setupPages([
			['id' => 'services',    'title' => 'Services',    'sort' => 0, 'parent' => '',         'draft' => false, 'nav' => true],
			['id' => 'web-design',  'title' => 'Web Design',  'sort' => 0, 'parent' => 'services', 'draft' => false, 'nav' => true],
			['id' => 'seo',         'title' => 'SEO',         'sort' => 1, 'parent' => 'services', 'draft' => false, 'nav' => true],
			['id' => 'about',       'title' => 'About',       'sort' => 0, 'parent' => '',         'draft' => false, 'nav' => true],
		]);

		$result = $this->adapter->subnav('services');

		$ids = array_column($result, 'id');
		$this->assertSame(['web-design', 'seo'], $ids);
	}

	public function testSubnavExcludesDraftChildren(): void
	{
		$this->setupPages([
			['id' => 'child1', 'title' => 'Child 1', 'sort' => 0, 'parent' => 'root', 'draft' => false, 'nav' => true],
			['id' => 'child2', 'title' => 'Child 2', 'sort' => 1, 'parent' => 'root', 'draft' => true,  'nav' => true],
		]);

		$result = $this->adapter->subnav('root');

		$ids = array_column($result, 'id');
		$this->assertSame(['child1'], $ids);
	}

	public function testSubnavExcludesNavFalseChildren(): void
	{
		$this->setupPages([
			['id' => 'visible', 'title' => 'Visible', 'sort' => 0, 'parent' => 'root', 'draft' => false, 'nav' => true],
			['id' => 'hidden',  'title' => 'Hidden',  'sort' => 1, 'parent' => 'root', 'draft' => false, 'nav' => false],
		]);

		$result = $this->adapter->subnav('root');

		$ids = array_column($result, 'id');
		$this->assertSame(['visible'], $ids);
	}

	public function testSubnavReturnsEmptyForNoChildren(): void
	{
		$this->setupPages([
			['id' => 'lonely', 'title' => 'Lonely', 'sort' => 0, 'parent' => '', 'draft' => false, 'nav' => true],
		]);

		$result = $this->adapter->subnav('lonely');

		$this->assertSame([], $result);
	}

	public function testSubnavUsesCustomCollection(): void
	{
		$this->indexReader->expects($this->once())
			->method('fetchIndex')
			->with('custom-pages')
			->willReturn(new IndexData([
				['id' => 'child', 'title' => 'Child', 'sort' => 0, 'parent' => 'root', 'draft' => false, 'nav' => true],
			]));

		$result = $this->adapter->subnav('root', 'custom-pages');

		$this->assertCount(1, $result);
	}

	// --- navTree() ---

	public function testNavTreeNestsChildrenUnderParents(): void
	{
		$this->setupPages([
			['id' => 'home',       'title' => 'Home',       'sort' => 0, 'parent' => '',         'draft' => false, 'nav' => true],
			['id' => 'services',   'title' => 'Services',   'sort' => 1, 'parent' => '',         'draft' => false, 'nav' => true],
			['id' => 'web-design', 'title' => 'Web Design', 'sort' => 0, 'parent' => 'services', 'draft' => false, 'nav' => true],
			['id' => 'seo',        'title' => 'SEO',        'sort' => 1, 'parent' => 'services', 'draft' => false, 'nav' => true],
		]);

		$tree = $this->adapter->navTree();

		$this->assertCount(2, $tree);
		$this->assertSame('home', $tree[0]['id']);
		$this->assertSame([], $tree[0]['children']);
		$this->assertSame('services', $tree[1]['id']);
		$this->assertCount(2, $tree[1]['children']);
		$this->assertSame('web-design', $tree[1]['children'][0]['id']);
		$this->assertSame('seo', $tree[1]['children'][1]['id']);
	}

	public function testNavTreeHandlesThreeLevels(): void
	{
		$this->setupPages([
			['id' => 'root',       'title' => 'Root',       'sort' => 0, 'parent' => '',       'draft' => false, 'nav' => true],
			['id' => 'child',      'title' => 'Child',      'sort' => 0, 'parent' => 'root',   'draft' => false, 'nav' => true],
			['id' => 'grandchild', 'title' => 'Grandchild', 'sort' => 0, 'parent' => 'child',  'draft' => false, 'nav' => true],
		]);

		$tree = $this->adapter->navTree();

		$this->assertCount(1, $tree);
		$this->assertSame('root', $tree[0]['id']);
		$this->assertCount(1, $tree[0]['children']);
		$this->assertSame('child', $tree[0]['children'][0]['id']);
		$this->assertCount(1, $tree[0]['children'][0]['children']);
		$this->assertSame('grandchild', $tree[0]['children'][0]['children'][0]['id']);
	}

	public function testNavTreeExcludesDraftPages(): void
	{
		$this->setupPages([
			['id' => 'root',  'title' => 'Root',  'sort' => 0, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'draft', 'title' => 'Draft', 'sort' => 0, 'parent' => 'root', 'draft' => true,  'nav' => true],
		]);

		$tree = $this->adapter->navTree();

		$this->assertCount(1, $tree);
		$this->assertSame([], $tree[0]['children']);
	}

	public function testNavTreeExcludesNavFalsePages(): void
	{
		$this->setupPages([
			['id' => 'root',   'title' => 'Root',   'sort' => 0, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'hidden', 'title' => 'Hidden', 'sort' => 0, 'parent' => 'root', 'draft' => false, 'nav' => false],
		]);

		$tree = $this->adapter->navTree();

		$this->assertCount(1, $tree);
		$this->assertSame([], $tree[0]['children']);
	}

	public function testNavTreeReturnsEmptyForNoPages(): void
	{
		$this->setupPages([]);

		$tree = $this->adapter->navTree();

		$this->assertSame([], $tree);
	}

	public function testNavTreeChildrenHaveChildrenKey(): void
	{
		$this->setupPages([
			['id' => 'leaf', 'title' => 'Leaf', 'sort' => 0, 'parent' => '', 'draft' => false, 'nav' => true],
		]);

		$tree = $this->adapter->navTree();

		$this->assertArrayHasKey('children', $tree[0]);
		$this->assertSame([], $tree[0]['children']);
	}
}

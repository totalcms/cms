<?php

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Twig\Adapter\BuilderTwigAdapter;
use TotalCMS\Support\Config;

final class BuilderTwigAdapterTest extends TestCase
{
	private BuilderTwigAdapter $adapter;
	private \PHPUnit\Framework\MockObject\MockObject $builderConfig;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;
	private \PHPUnit\Framework\MockObject\MockObject $orderService;
	private \PHPUnit\Framework\MockObject\MockObject $config;

	protected function setUp(): void
	{
		$this->builderConfig = $this->createMock(BuilderConfigService::class);
		$this->indexReader   = $this->createMock(IndexReader::class);
		$this->orderService  = $this->createMock(BuilderOrderService::class);
		$this->config        = $this->createMock(Config::class);

		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->config->builder = ['assetsPath' => 'assets'];
		$this->config->docroot = sys_get_temp_dir();
		$this->config->api     = '';

		$this->adapter = new BuilderTwigAdapter(
			$this->builderConfig,
			$this->indexReader,
			$this->orderService,
			$this->config,
		);
	}

	/**
	 * Configure both the page index AND the order tree so the adapter has
	 * everything it needs to build navigation. Pages without an explicit
	 * parent in the legacy `parent` field land at root in the order tree.
	 *
	 * @param list<array<string,mixed>> $pages
	 */
	private function setupPages(array $pages): void
	{
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($pages));
		$this->orderService->method('read')->willReturn($this->buildOrderTreeFromLegacyPages($pages));
	}

	/**
	 * @param  list<array<string,mixed>> $pages
	 *
	 * @return list<array{id:string,children:list<array<string,mixed>>}>
	 */
	private function buildOrderTreeFromLegacyPages(array $pages): array
	{
		// Sort by legacy sort field, then group by parent, then build a tree.
		usort($pages, static fn (array $a, array $b): int => ((int)($a['sort'] ?? 0)) <=> ((int)($b['sort'] ?? 0)));

		$byParent = [];
		foreach ($pages as $p) {
			$byParent[(string)($p['parent'] ?? '')][] = (string)($p['id'] ?? '');
		}

		$build = static function (string $parentId) use (&$build, $byParent): array {
			$out = [];
			foreach ($byParent[$parentId] ?? [] as $id) {
				$out[] = ['id' => $id, 'children' => $build($id)];
			}

			return $out;
		};

		return $build('');
	}

	private function samplePages(): array
	{
		return [
			['id' => 'home',     'title' => 'Home',     'route' => '/',          'sort' => 0, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'about',    'title' => 'About',    'route' => '/about',     'sort' => 1, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'blog',     'title' => 'Blog',     'route' => '/blog',      'sort' => 2, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'blog-post', 'title' => 'Blog Post', 'route' => '/blog/{id}', 'sort' => 3, 'parent' => 'blog', 'draft' => false, 'nav' => false],
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
				['id' => 'page1', 'title' => 'Page 1', 'draft' => false, 'nav' => true],
			]));
		$this->orderService->expects($this->once())
			->method('read')
			->with('custom-pages')
			->willReturn([['id' => 'page1', 'children' => []]]);

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
		// `root` itself must exist as a page now — the order tree only contains
		// real pages, so subnav() walks the tree to find the parent.
		$this->setupPages([
			['id' => 'root',   'title' => 'Root',   'sort' => 0, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'child1', 'title' => 'Child 1', 'sort' => 1, 'parent' => 'root', 'draft' => false, 'nav' => true],
			['id' => 'child2', 'title' => 'Child 2', 'sort' => 2, 'parent' => 'root', 'draft' => true,  'nav' => true],
		]);

		$result = $this->adapter->subnav('root');

		$ids = array_column($result, 'id');
		$this->assertSame(['child1'], $ids);
	}

	public function testSubnavExcludesNavFalseChildren(): void
	{
		$this->setupPages([
			['id' => 'root',    'title' => 'Root',    'sort' => 0, 'parent' => '',     'draft' => false, 'nav' => true],
			['id' => 'visible', 'title' => 'Visible', 'sort' => 1, 'parent' => 'root', 'draft' => false, 'nav' => true],
			['id' => 'hidden',  'title' => 'Hidden',  'sort' => 2, 'parent' => 'root', 'draft' => false, 'nav' => false],
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
				['id' => 'root',  'title' => 'Root',  'draft' => false, 'nav' => true],
				['id' => 'child', 'title' => 'Child', 'draft' => false, 'nav' => true],
			]));
		$this->orderService->expects($this->once())
			->method('read')
			->with('custom-pages')
			->willReturn([
				['id' => 'root', 'children' => [
					['id' => 'child', 'children' => []],
				]],
			]);

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

	// --- pagesTree() ---

	public function testPagesTreeIncludesDrafts(): void
	{
		$this->setupPages($this->samplePages());

		$tree = $this->adapter->pagesTree();

		$ids = array_column($tree, 'id');
		$this->assertContains('drafts', $ids);
	}

	public function testPagesTreeIncludesNavFalsePages(): void
	{
		$this->setupPages($this->samplePages());

		$tree = $this->adapter->pagesTree();

		$ids = array_column($tree, 'id');
		$this->assertContains('privacy', $ids);
	}

	public function testPagesTreeNestsChildrenUnderParent(): void
	{
		$this->setupPages($this->samplePages());

		$tree = $this->adapter->pagesTree();

		// Find blog node — blog-post has parent=blog so it should nest there
		$blog = null;
		foreach ($tree as $node) {
			if ($node['id'] === 'blog') {
				$blog = $node;
				break;
			}
		}
		$this->assertNotNull($blog);
		$this->assertCount(1, $blog['children']);
		$this->assertSame('blog-post', $blog['children'][0]['id']);
	}

	public function testPagesTreeSortsBySortField(): void
	{
		$this->setupPages([
			['id' => 'c', 'title' => 'C', 'sort' => 2, 'parent' => '', 'draft' => false],
			['id' => 'a', 'title' => 'A', 'sort' => 0, 'parent' => '', 'draft' => false],
			['id' => 'b', 'title' => 'B', 'sort' => 1, 'parent' => '', 'draft' => true],
		]);

		$tree = $this->adapter->pagesTree();

		$ids = array_column($tree, 'id');
		$this->assertSame(['a', 'b', 'c'], $ids);
	}

	public function testPagesTreeReturnsEmptyOnIndexFailure(): void
	{
		$this->indexReader->method('fetchIndex')->willThrowException(new \Exception('not found'));

		$this->assertSame([], $this->adapter->pagesTree());
	}

	// --- url() ---

	public function testUrlReturnsRouteForStaticPage(): void
	{
		$this->setupPages($this->samplePages());

		$this->assertSame('/about', $this->adapter->url('about'));
		$this->assertSame('/contact', $this->adapter->url('contact'));
	}

	public function testUrlFillsDynamicParam(): void
	{
		$this->setupPages($this->samplePages());

		$this->assertSame('/blog/hello', $this->adapter->url('blog-post', ['id' => 'hello']));
	}

	public function testUrlReturnsEmptyForMissingPage(): void
	{
		$this->setupPages($this->samplePages());

		$this->assertSame('', $this->adapter->url('nonexistent'));
	}

	public function testUrlReturnsEmptyForPageWithoutRoute(): void
	{
		$this->setupPages([
			['id' => 'orphan', 'title' => 'Orphan', 'route' => '', 'sort' => 0, 'parent' => '', 'draft' => false, 'nav' => true],
		]);

		$this->assertSame('', $this->adapter->url('orphan'));
	}

	public function testUrlLeavesUnfilledPlaceholders(): void
	{
		$this->setupPages($this->samplePages());

		// Param missing — placeholder remains visible so the broken reference is obvious
		$this->assertSame('/blog/{id}', $this->adapter->url('blog-post'));
	}

	public function testUrlEncodesSpecialCharactersInParams(): void
	{
		$this->setupPages($this->samplePages());

		$this->assertSame('/blog/hello%20world', $this->adapter->url('blog-post', ['id' => 'hello world']));
	}

	public function testUrlPrefixesWithApiBase(): void
	{
		$this->config->api = '/myapp';
		$this->setupPages($this->samplePages());

		$this->assertSame('/myapp/about', $this->adapter->url('about'));
	}

	public function testUrlReturnsEmptyWhenIndexFetchFails(): void
	{
		$this->indexReader->method('fetchIndex')->willThrowException(new \Exception('not found'));

		$this->assertSame('', $this->adapter->url('about'));
	}

	public function testUrlIgnoresExtraParams(): void
	{
		$this->setupPages($this->samplePages());

		$this->assertSame('/about', $this->adapter->url('about', ['extra' => 'unused']));
	}

	// --- stacksPage() ---

	public function testStacksPageReadsHtmlFile(): void
	{
		$dir = sys_get_temp_dir() . '/stacks-test-' . uniqid();
		mkdir($dir, 0755, true);
		$this->config->docroot = $dir;
		file_put_contents($dir . '/about.html', '<html><body><h1>About</h1></body></html>');

		$result = $this->adapter->stacksPage('/about.html');

		$this->assertSame('<html><body><h1>About</h1></body></html>', $result);

		@unlink($dir . '/about.html');
		@rmdir($dir);
	}

	public function testStacksPageTriesHtmlSuffix(): void
	{
		$dir = sys_get_temp_dir() . '/stacks-test-' . uniqid();
		mkdir($dir, 0755, true);
		$this->config->docroot = $dir;
		file_put_contents($dir . '/about.html', 'about content');

		$result = $this->adapter->stacksPage('/about');

		$this->assertSame('about content', $result);

		@unlink($dir . '/about.html');
		@rmdir($dir);
	}

	public function testStacksPageTriesIndexHtmlInDirectory(): void
	{
		$dir = sys_get_temp_dir() . '/stacks-test-' . uniqid();
		mkdir($dir . '/blog', 0755, true);
		$this->config->docroot = $dir;
		file_put_contents($dir . '/blog/index.html', 'blog index');

		$result = $this->adapter->stacksPage('/blog');

		$this->assertSame('blog index', $result);

		@unlink($dir . '/blog/index.html');
		@rmdir($dir . '/blog');
		@rmdir($dir);
	}

	public function testStacksPageExtractsBodyContent(): void
	{
		$dir = sys_get_temp_dir() . '/stacks-test-' . uniqid();
		mkdir($dir, 0755, true);
		$this->config->docroot = $dir;
		file_put_contents(
			$dir . '/page.html',
			"<!doctype html><html><head><title>X</title></head><body class=\"foo\">\n<h1>Hello</h1>\n</body></html>",
		);

		$result = $this->adapter->stacksPage('/page.html', 'body');

		$this->assertSame("\n<h1>Hello</h1>\n", $result);

		@unlink($dir . '/page.html');
		@rmdir($dir);
	}

	public function testStacksPageExtractsCustomTag(): void
	{
		$dir = sys_get_temp_dir() . '/stacks-test-' . uniqid();
		mkdir($dir, 0755, true);
		$this->config->docroot = $dir;
		file_put_contents(
			$dir . '/page.html',
			'<html><body><nav id="primary"><a href="/">Home</a></nav><main>Body</main></body></html>',
		);

		$result = $this->adapter->stacksPage('/page.html', 'nav');

		$this->assertSame('<a href="/">Home</a>', $result);

		@unlink($dir . '/page.html');
		@rmdir($dir);
	}

	public function testStacksPageReturnsEmptyForMissingFile(): void
	{
		$dir = sys_get_temp_dir() . '/stacks-test-' . uniqid();
		mkdir($dir, 0755, true);
		$this->config->docroot = $dir;

		$this->assertSame('', $this->adapter->stacksPage('/missing'));

		@rmdir($dir);
	}

	public function testStacksPageBlocksPathTraversal(): void
	{
		$this->assertSame('', $this->adapter->stacksPage('../../etc/passwd'));
		$this->assertSame('', $this->adapter->stacksPage('/legit/../../../secret'));
	}

	public function testStacksPageBlocksEmptyPath(): void
	{
		$this->assertSame('', $this->adapter->stacksPage(''));
		$this->assertSame('', $this->adapter->stacksPage('/'));
	}

	// --- asset() ---

	public function testAssetReturnsPathWithMtimeWhenFileExists(): void
	{
		$dir = sys_get_temp_dir() . '/assets';
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		$file = $dir . '/style.css';
		file_put_contents($file, 'body{}');

		$result = $this->adapter->asset('style.css');

		$this->assertStringStartsWith('/assets/style.css?v=', $result);
		@unlink($file);
	}

	public function testAssetReturnsRawPathWhenFileDoesNotExist(): void
	{
		$result = $this->adapter->asset('nonexistent.css');

		$this->assertSame('/assets/nonexistent.css', $result);
	}

	// --- css() ---

	public function testCssOutputsLinkTag(): void
	{
		$result = $this->adapter->css('style.css');

		$this->assertStringContainsString('<link rel="stylesheet" href="', $result);
		$this->assertStringContainsString('/assets/style.css', $result);
		$this->assertStringContainsString('>', $result);
	}

	// --- js() ---

	public function testJsOutputsScriptTag(): void
	{
		$result = $this->adapter->js('app.js');

		$this->assertStringContainsString('<script src="', $result);
		$this->assertStringContainsString('/assets/app.js', $result);
		$this->assertStringContainsString('></script>', $result);
	}

	public function testJsWithModuleOption(): void
	{
		$result = $this->adapter->js('app.js', ['module' => true]);

		$this->assertStringContainsString('type="module"', $result);
		$this->assertStringContainsString('/assets/app.js', $result);
	}

	public function testJsWithoutModuleOption(): void
	{
		$result = $this->adapter->js('app.js');

		$this->assertStringNotContainsString('type="module"', $result);
	}

	// --- preload() ---

	public function testPreloadOutputsPreloadTag(): void
	{
		$result = $this->adapter->preload('hero.webp', 'image');

		$this->assertStringContainsString('<link rel="preload" href="', $result);
		$this->assertStringContainsString('/assets/hero.webp', $result);
		$this->assertStringContainsString('as="image"', $result);
	}

	public function testPreloadAddsCrossoriginForFonts(): void
	{
		$result = $this->adapter->preload('fonts/inter.woff2', 'font');

		$this->assertStringContainsString('as="font"', $result);
		$this->assertStringContainsString('crossorigin', $result);
	}

	public function testPreloadNoCrossoriginForNonFonts(): void
	{
		$result = $this->adapter->preload('app.js', 'script');

		$this->assertStringNotContainsString('crossorigin', $result);
	}

	// --- manifest ---

	public function testAssetResolvesFromManifest(): void
	{
		$dir = sys_get_temp_dir() . '/assets';
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($dir . '/manifest.json', json_encode([
			'css/style.css' => ['file' => 'css/style.a1b2c3.css'],
			'js/app.js'     => ['file' => 'js/app.d4e5f6.js'],
		]));

		// Need a fresh adapter to reset the static manifest cache
		$adapter = new BuilderTwigAdapter(
			$this->builderConfig,
			$this->indexReader,
			$this->orderService,
			$this->config,
		);

		$result = $adapter->asset('css/style.css');
		$this->assertSame('/assets/css/style.a1b2c3.css', $result);

		$result = $adapter->asset('js/app.js');
		$this->assertSame('/assets/js/app.d4e5f6.js', $result);

		@unlink($dir . '/manifest.json');
	}
}

<?php

declare(strict_types=1);

use TotalCMS\Bundled\ClientHelp\Extension;
use TotalCMS\Bundled\ClientHelp\HelpLibrary;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Docs\Service\DocsMarkdownRenderer;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

$dir = dirname(__DIR__, 4) . '/resources/extensions/totalcms/client-help';
require_once $dir . '/Extension.php';
require_once $dir . '/HelpLibrary.php';

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();

	$this->makeCollection = function (string $schema = 'blogdown') use ($container): void {
		$container->get(CollectionSaver::class)->saveCollection(['id' => Extension::COLLECTION, 'name' => 'Help', 'schema' => $schema]);
	};
	$this->post = function (string $id, array $data = []) use ($container): void {
		$container->get(ObjectSaver::class)->saveObject(Extension::COLLECTION, ['id' => $id, 'title' => ucfirst($id)] + $data);
	};
	$this->library = new HelpLibrary(
		$container->get(CollectionTwigAdapter::class),
		$container->get(SchemaFetcher::class),
		new DocsMarkdownRenderer(),
		$container->get(VideoUrlResolver::class),
	);
	$container->get(CollectionFetcher::class)->clearCache();
});

test('drafts are left out of published posts', function (): void {
	($this->makeCollection)();
	($this->post)('live');
	($this->post)('wip', ['draft' => true]);

	expect(array_column($this->library->published(), 'id'))->toBe(['live']);
});

test('groups sort by name and posts by title', function (): void {
	($this->makeCollection)();
	($this->post)('banana', ['categories' => ['Your Menu']]);
	($this->post)('apple', ['categories' => ['Your Menu']]);
	($this->post)('zebra', ['categories' => ['about']]);

	$groups = $this->library->groups();

	expect(array_column($groups, 'title'))->toBe(['about', 'Your Menu']);
	expect(array_column($groups[1]['posts'], 'id'))->toBe(['apple', 'banana']);
});

test('a post in two categories appears in both groups', function (): void {
	($this->makeCollection)();
	($this->post)('both', ['categories' => ['One', 'Two']]);

	foreach ($this->library->groups() as $group) {
		expect(array_column($group['posts'], 'id'))->toBe(['both']);
	}
	expect($this->library->groups())->toHaveCount(2);
});

test('a post with no usable category goes under General', function (): void {
	($this->makeCollection)();
	($this->post)('none');
	($this->post)('blank', ['categories' => ['   ']]);

	$groups = $this->library->groups();

	expect(array_column($groups, 'title'))->toBe(['General']);
	expect(array_column($groups[0]['posts'], 'id'))->toBe(['blank', 'none']);
});

test('categories are trimmed so they share a group', function (): void {
	($this->makeCollection)();
	($this->post)('one', ['categories' => [' Menu ']]);
	($this->post)('two', ['categories' => ['Menu']]);

	expect(array_column($this->library->groups(), 'title'))->toBe(['Menu']);
});

test('featured returns published featured posts only', function (): void {
	($this->makeCollection)();
	($this->post)('star', ['featured' => true]);
	($this->post)('plain');
	($this->post)('hidden', ['featured' => true, 'draft' => true]);

	expect(array_column($this->library->featured(), 'id'))->toBe(['star']);
});

test('a missing post and a draft are the same to a reader', function (): void {
	($this->makeCollection)();
	($this->post)('wip', ['draft' => true]);

	expect($this->library->article('missing'))->toBeNull();
	expect($this->library->article('wip'))->toBeNull();
});

test('a Markdown post renders with headings and without scripts', function (): void {
	($this->makeCollection)();
	($this->post)('steps', ['content' => "## Steps\n\n<script>alert(1)</script>", 'categories' => ['Menu', 'Other']]);

	$article = $this->library->article('steps');

	expect($article['html'])->toContain('id="steps"')->not->toContain('<script');
	expect($article['toc'])->toHaveCount(1);
	expect($article['category'])->toBe('Menu');
});

test('a post on the blog schema keeps its HTML and loses scripts', function (): void {
	($this->makeCollection)('blog');
	($this->post)('rich', ['content' => '<p>Hi</p><script>alert(1)</script>']);

	$article = $this->library->article('rich');

	expect($article['html'])->toContain('<p>Hi</p>')->not->toContain('<script');
	expect($article['category'])->toBe('General');
});

test('a post with no body still opens', function (): void {
	($this->makeCollection)();
	($this->post)('empty');

	expect($this->library->article('empty')['html'])->toBe('');
});

test('summary and extra render like the body', function (): void {
	($this->makeCollection)();
	($this->post)('full', ['summary' => 'Short **bold**<script>x</script>', 'content' => '## One', 'extra' => "## Two\n\n<script>y</script>"]);

	$article = $this->library->article('full');

	expect($article['summary'])->toContain('<strong>bold</strong>')->not->toContain('<script');
	expect($article['extra'])->toContain('id="two"')->not->toContain('<script');
	expect($article['html'])->not->toContain('Two');
});

test('extra headings join the page list without repeating an anchor', function (): void {
	($this->makeCollection)();
	($this->post)('dupe', ['content' => '## Same', 'extra' => '## Same']);

	$article = $this->library->article('dupe');

	expect(array_column($article['toc'], 'id'))->toBe(['same', 'same-2']);
});

test('a video URL embeds and any other web URL is a link', function (): void {
	($this->makeCollection)();
	($this->post)('video', ['media' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
	($this->post)('page', ['media' => 'https://example.com/guide']);

	expect($this->library->article('video')['media'])->toContain('<iframe')->toContain('youtube');
	expect($this->library->article('page')['media'])->toBe('');
	expect($this->library->article('page')['mediaUrl'])->toBe('https://example.com/guide');
});

test('a media URL that is not a web address is dropped', function (string $url): void {
	($this->makeCollection)();
	$collections = $this->createStub(CollectionTwigAdapter::class);
	$collections->method('object')->willReturn(['id' => 'bad', 'title' => 'Bad', 'media' => $url]);
	$container = $this->app->getContainer();
	$library   = new HelpLibrary($collections, $container->get(SchemaFetcher::class), new DocsMarkdownRenderer(), $container->get(VideoUrlResolver::class));

	$article = $library->article('bad');

	expect($article['media'])->toBe('');
	expect($article['mediaUrl'])->toBe('');
})->with(['javascript:alert(1)', 'data:text/html,x', '/admin/settings', '//evil.example/x']);

test('dates are readable and a bad one is left out', function (): void {
	($this->makeCollection)();
	($this->post)('dated', ['date' => '2026-03-09']);

	expect($this->library->article('dated')['date'])->toBe('March 9, 2026');
	expect(HelpLibrary::readableDate('not a date'))->toBe('');
	expect(HelpLibrary::readableDate(''))->toBe('');
});

test('featured posts carry a plain text excerpt', function (): void {
	($this->makeCollection)();
	($this->post)('star', ['featured' => true, 'summary' => 'Fish **&** chips']);

	expect($this->library->featured()[0]['excerpt'])->toBe('Fish & chips');
});

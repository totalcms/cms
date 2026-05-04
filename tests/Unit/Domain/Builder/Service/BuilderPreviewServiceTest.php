<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderPreviewService;
use TotalCMS\Domain\Builder\Service\PageRouter;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;

final class BuilderPreviewServiceTest extends TestCase
{
	private TwigEngine&MockObject $twig;
	private BuilderConfigService&MockObject $config;
	private IndexReader&MockObject $indexReader;
	private ObjectFetcher&MockObject $objectFetcher;
	private PageRouter&MockObject $pageRouter;
	private BuilderPreviewService $service;

	protected function setUp(): void
	{
		$this->twig          = $this->createMock(TwigEngine::class);
		$this->config        = $this->createMock(BuilderConfigService::class);
		$this->indexReader   = $this->createMock(IndexReader::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->pageRouter    = $this->createMock(PageRouter::class);

		$this->config->method('getPagesCollectionId')->willReturn('builder-pages');

		$this->service = new BuilderPreviewService(
			$this->twig,
			$this->config,
			$this->indexReader,
			$this->objectFetcher,
			$this->pageRouter,
		);
	}

	// --- Empty content / pathless ---

	public function testReturnsEmptyStringForEmptyContent(): void
	{
		$this->twig->expects($this->never())->method('renderWithOverride');
		$this->twig->expects($this->never())->method('renderString');

		$this->assertSame('', $this->service->render('pages/about.twig', '', '', ''));
	}

	public function testEmptyPathFallsBackToStringRender(): void
	{
		$this->twig->expects($this->once())
			->method('renderString')
			->with('{{ 1 + 1 }}')
			->willReturn('2');

		$this->assertSame('2', $this->service->render('', '{{ 1 + 1 }}', '', ''));
	}

	// --- previewUrl path ---

	public function testPreviewUrlMatchingBuilderPageExposesPageVariable(): void
	{
		$pageData = ['id' => 'about', 'title' => 'About', 'template' => 'about'];
		$this->pageRouter->method('match')
			->with('/about')
			->willReturn(new RouteMatch(
				template: 'pages/about.twig',
				pageData: $pageData,
				params: ['foo' => 'bar'],
			));

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->with(
				'pages/about.twig',
				'<h1>{{ page.title }}</h1>',
				$this->callback(fn (array $data): bool => ($data['page'] ?? null) === $pageData
						&& ($data['params'] ?? null) === ['foo' => 'bar']
						&& !array_key_exists('object', $data)),
			)
			->willReturn('<h1>About</h1>');

		$result = $this->service->render('pages/about.twig', '<h1>{{ page.title }}</h1>', '/about', '');

		$this->assertSame('<h1>About</h1>', $result);
	}

	public function testPreviewUrlMatchingCollectionExposesObjectVariable(): void
	{
		$objectData = ['id' => 'first-post', 'title' => 'First Post'];
		$this->pageRouter->method('match')
			->with('/blog/first-post')
			->willReturn(new RouteMatch(
				template: 'pages/blog.twig',
				pageData: $objectData,
				params: ['id' => 'first-post'],
				collection: 'blog',
			));

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->with(
				'pages/blog.twig',
				'<article>{{ object.title }}</article>',
				$this->callback(fn (array $data): bool => ($data['object'] ?? null) === $objectData
						&& ($data['params'] ?? null) === ['id' => 'first-post']
						&& !array_key_exists('page', $data)),
			)
			->willReturn('<article>First Post</article>');

		$result = $this->service->render('pages/blog.twig', '<article>{{ object.title }}</article>', '/blog/first-post', '');

		$this->assertSame('<article>First Post</article>', $result);
	}

	public function testPreviewUrlNoMatchFallsThroughToPathBasedContext(): void
	{
		// previewUrl is supplied but doesn't resolve. Service should NOT
		// return empty; it falls through to the page-template context lookup
		// so a page with that template still renders something useful.
		$this->pageRouter->method('match')->willReturn(null);

		$this->indexReader->method('fetchIndex')
			->with('builder-pages')
			->willReturn(new IndexData([
				['id' => 'about', 'title' => 'About', 'template' => 'about'],
			]));

		$obj = $this->createMock(ObjectData::class);
		$obj->method('toArray')->willReturn(['id' => 'about', 'title' => 'About', 'template' => 'about']);
		$this->objectFetcher->method('fetchObject')->willReturn($obj);

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->with(
				'pages/about.twig',
				'<h1>{{ page.title }}</h1>',
				$this->callback(static fn (array $data): bool => ($data['page']['title'] ?? '') === 'About'),
			)
			->willReturn('<h1>About</h1>');

		$result = $this->service->render('pages/about.twig', '<h1>{{ page.title }}</h1>', '/no-such-route', '');
		$this->assertSame('<h1>About</h1>', $result);
	}

	// --- Path-based context (no previewUrl) ---

	public function testExplicitPageIdResolvesByObjectFetch(): void
	{
		$obj = $this->createMock(ObjectData::class);
		$obj->method('toArray')->willReturn([
			'id'       => 'about',
			'title'    => 'About From Fetch',
			'template' => 'about',
		]);
		$this->objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('builder-pages', 'about')
			->willReturn($obj);

		// Index lookup should never happen — explicit pageId is the fast path.
		$this->indexReader->expects($this->never())->method('fetchIndex');

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->with(
				'pages/about.twig',
				'<h1>{{ page.title }}</h1>',
				$this->callback(static fn (array $data): bool => ($data['page']['title'] ?? '') === 'About From Fetch'),
			)
			->willReturn('<h1>About From Fetch</h1>');

		$this->service->render('pages/about.twig', '<h1>{{ page.title }}</h1>', '', 'about');
	}

	public function testFallsBackToFirstPageMatchingTemplateName(): void
	{
		// No pageId given, no previewUrl. Service walks the index to find
		// the first page that uses this template.
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'home', 'title' => 'Home', 'template' => 'index'],
			['id' => 'about', 'title' => 'About', 'template' => 'about'],
			['id' => 'about-us', 'title' => 'About Us', 'template' => 'about'],
		]));

		$obj = $this->createMock(ObjectData::class);
		$obj->method('toArray')->willReturn(['id' => 'about', 'title' => 'About']);
		$this->objectFetcher->expects($this->once())
			->method('fetchObject')
			->with('builder-pages', 'about')
			->willReturn($obj);

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->willReturnCallback(function (string $name, string $content, array $data): string {
				$this->assertSame('About', $data['page']['title'] ?? '');

				return 'rendered';
			});

		$this->service->render('pages/about.twig', 'tpl', '', '');
	}

	public function testNoMatchingTemplateRendersWithEmptyPage(): void
	{
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'home', 'template' => 'index'],
		]));

		$this->objectFetcher->expects($this->never())->method('fetchObject');

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->with(
				'pages/about.twig',
				'tpl',
				$this->callback(
					// Empty PageData is rendered — page.title is empty string.
					static fn (array $data): bool => isset($data['page']) && ($data['page']['title'] ?? null) === ''
						&& ($data['params'] ?? null) === []
				),
			)
			->willReturn('rendered');

		$this->service->render('pages/about.twig', 'tpl', '', '');
	}

	public function testNonPageTemplatePathRendersWithEmptyPage(): void
	{
		// e.g., a layout or partial — no page lookup, just an empty PageData.
		$this->indexReader->expects($this->never())->method('fetchIndex');
		$this->objectFetcher->expects($this->never())->method('fetchObject');

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->with(
				'layouts/default.twig',
				'tpl',
				$this->callback(static fn (array $data): bool => isset($data['page']) && ($data['params'] ?? null) === []),
			)
			->willReturn('rendered');

		$this->service->render('layouts/default.twig', 'tpl', '', '');
	}

	// --- Error handling ---

	public function testRendersTwigErrorAsStyledErrorBox(): void
	{
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([]));
		$this->twig->method('renderWithOverride')
			->willThrowException(new \RuntimeException('Unexpected token <foo>'));

		$result = $this->service->render('pages/about.twig', 'invalid', '', '');

		$this->assertStringContainsString('cms-twig-error', $result);
		$this->assertStringContainsString('Preview error', $result);
		// Error message must be HTML-escaped (`<foo>` should appear escaped).
		$this->assertStringContainsString('&lt;foo&gt;', $result);
	}

	public function testExplicitPageIdFetchFailureFallsBackToEmptyPage(): void
	{
		// pageId is given but the object can't be fetched — service should
		// not error; renders with an empty PageData instead.
		$this->objectFetcher->method('fetchObject')->willThrowException(new \DomainException('not found'));

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->with(
				'pages/about.twig',
				'tpl',
				$this->callback(static fn (array $data): bool => ($data['page']['title'] ?? null) === ''),
			)
			->willReturn('rendered');

		$this->service->render('pages/about.twig', 'tpl', '', 'about');
	}

	public function testIndexReadFailureFallsBackToEmptyPage(): void
	{
		$this->indexReader->method('fetchIndex')->willThrowException(new \RuntimeException('disk error'));
		$this->objectFetcher->expects($this->never())->method('fetchObject');

		$this->twig->expects($this->once())
			->method('renderWithOverride')
			->with(
				'pages/about.twig',
				'tpl',
				$this->callback(static fn (array $data): bool => isset($data['page'])),
			)
			->willReturn('rendered');

		$this->service->render('pages/about.twig', 'tpl', '', '');
	}
}

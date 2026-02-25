<?php

namespace Tests\Unit\Domain\Twig\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;

final class HtmxRendererTest extends TestCase
{
	private HtmxRenderer $renderer;

	protected function setUp(): void
	{
		$this->renderer = new HtmxRenderer();
	}

	// --- buildNextPageTrigger ---

	public function testBuildNextPageTriggerReturnsEmptyWhenNoMore(): void
	{
		$result = new QueryResult([['id' => '1']], 1, 20, 0);

		$html = $this->renderer->buildNextPageTrigger('/api/collections/blog/query', $result, []);

		$this->assertSame('', $html);
	}

	public function testBuildNextPageTriggerGeneratesDiv(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 20, 0);

		$html = $this->renderer->buildNextPageTrigger('/api/collections/blog/query', $result, [
			'template' => 'blog/card',
			'limit'    => '20',
		]);

		$this->assertStringContainsString('hx-get=', $html);
		$this->assertStringContainsString('hx-trigger=', $html);
		$this->assertStringContainsString('hx-swap=', $html);
		$this->assertStringContainsString('offset=20', $html);
		$this->assertStringContainsString('template=blog%2Fcard', $html);
		$this->assertStringContainsString('<div', $html);
	}

	public function testBuildNextPageTriggerWithClickTriggerGeneratesButton(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 20, 0);

		$html = $this->renderer->buildNextPageTrigger('/api/query', $result, [
			'template' => 'card',
			'limit'    => '20',
			'trigger'  => 'click',
			'label'    => 'Show More',
		]);

		$this->assertStringContainsString('<button', $html);
		$this->assertStringContainsString('Show More', $html);
		$this->assertStringContainsString('hx-trigger="click"', $html);
	}

	public function testBuildNextPageTriggerCarriesForwardOptionalParams(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 20, 0);

		$html = $this->renderer->buildNextPageTrigger('/api/query', $result, [
			'template' => 'card',
			'limit'    => '20',
			'sort'     => 'date:desc',
			'include'  => 'published:true',
			'exclude'  => 'draft:true',
			'search'   => 'hello',
		]);

		$this->assertStringContainsString('sort=date%3Adesc', $html);
		$this->assertStringContainsString('include=published%3Atrue', $html);
		$this->assertStringContainsString('exclude=draft%3Atrue', $html);
		$this->assertStringContainsString('search=hello', $html);
	}

	public function testBuildNextPageTriggerWithTransition(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 20, 0);

		$html = $this->renderer->buildNextPageTrigger('/api/query', $result, [
			'template'   => 'card',
			'limit'      => '20',
			'transition' => '1',
		]);

		$this->assertStringContainsString('transition:true', $html);
	}

	// --- buildInitialTrigger ---

	public function testBuildInitialTriggerRevealedCreatesDiv(): void
	{
		$html = $this->renderer->buildInitialTrigger(
			'/api/collections/blog/query',
			['format' => 'html', 'template' => 'card', 'offset' => '20', 'limit' => '20'],
			'revealed',
		);

		$this->assertStringContainsString('<div', $html);
		$this->assertStringContainsString('hx-trigger="revealed"', $html);
		$this->assertStringContainsString('cms-load-more', $html);
	}

	public function testBuildInitialTriggerClickCreatesButton(): void
	{
		$html = $this->renderer->buildInitialTrigger(
			'/api/collections/blog/query',
			['format' => 'html', 'template' => 'card', 'offset' => '20', 'limit' => '20'],
			'click',
			'Load More Posts',
		);

		$this->assertStringContainsString('<button', $html);
		$this->assertStringContainsString('Load More Posts', $html);
		$this->assertStringContainsString('hx-trigger="click"', $html);
	}

	public function testBuildInitialTriggerWithTransition(): void
	{
		$html = $this->renderer->buildInitialTrigger(
			'/api/query',
			['format' => 'html', 'template' => 'card', 'offset' => '20', 'limit' => '20'],
			'revealed',
			'Load More',
			'',
			true,
		);

		$this->assertStringContainsString('transition:true', $html);
	}

	public function testBuildInitialTriggerWithExtraClass(): void
	{
		$html = $this->renderer->buildInitialTrigger(
			'/api/query',
			['format' => 'html', 'template' => 'card', 'offset' => '20', 'limit' => '20'],
			'revealed',
			'Load More',
			'my-custom-class',
		);

		$this->assertStringContainsString('cms-load-more my-custom-class', $html);
	}

	public function testBuildInitialTriggerEscapesLabel(): void
	{
		$html = $this->renderer->buildInitialTrigger(
			'/api/query',
			['format' => 'html', 'template' => 'card', 'offset' => '20', 'limit' => '20'],
			'click',
			'<script>alert("xss")</script>',
		);

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}

	public function testBuildInitialTriggerDefaultSwapIsOuterHTML(): void
	{
		$html = $this->renderer->buildInitialTrigger(
			'/api/query',
			['format' => 'html', 'template' => 'card', 'offset' => '20', 'limit' => '20'],
		);

		$this->assertStringContainsString('hx-swap="outerHTML"', $html);
	}
}

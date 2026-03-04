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
			'template'    => 'card',
			'limit'       => '20',
			'trigger'     => 'click',
			'buttonLabel' => 'Show More',
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

	public function testBuildNextPageTriggerCarriesForwardButtonClass(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 20, 0);

		$html = $this->renderer->buildNextPageTrigger('/api/query', $result, [
			'template'    => 'card',
			'limit'       => '20',
			'buttonClass' => 'my-custom-class',
		]);

		$this->assertStringContainsString('cms-load-more my-custom-class', $html);
		$this->assertStringContainsString('buttonClass=my-custom-class', $html);
	}

	public function testBuildNextPageTriggerCarriesForwardButtonLabel(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 20, 0);

		$html = $this->renderer->buildNextPageTrigger('/api/query', $result, [
			'template'    => 'card',
			'limit'       => '20',
			'trigger'     => 'click',
			'buttonLabel' => 'More Items',
		]);

		$this->assertStringContainsString('<button', $html);
		$this->assertStringContainsString('More Items', $html);
		$this->assertStringContainsString('buttonLabel=More+Items', $html);
	}

	public function testBuildInitialTriggerDefaultSwapIsOuterHTML(): void
	{
		$html = $this->renderer->buildInitialTrigger(
			'/api/query',
			['format' => 'html', 'template' => 'card', 'offset' => '20', 'limit' => '20'],
		);

		$this->assertStringContainsString('hx-swap="outerHTML"', $html);
	}

	// --- buildButton ---

	public function testBuildButtonOutputsCorrectAttributes(): void
	{
		$html = $this->renderer->buildButton(
			'/api/collections/blog/query',
			[
				'format'   => 'html',
				'template' => 'blog/card',
				'offset'   => '0',
				'limit'    => '10',
				'mode'     => 'append',
				'buttonId' => 'cms-lmb-abc12345',
				'target'   => '#blog-feed',
			],
		);

		$this->assertStringContainsString('<button', $html);
		$this->assertStringContainsString('id="cms-lmb-abc12345"', $html);
		$this->assertStringContainsString('hx-get=', $html);
		$this->assertStringContainsString('hx-target="#blog-feed"', $html);
		$this->assertStringContainsString('hx-swap="beforeend"', $html);
		$this->assertStringContainsString('hx-trigger="click"', $html);
		$this->assertStringContainsString('cms-load-more', $html);
		$this->assertStringContainsString('Load More', $html);
	}

	public function testBuildButtonWithLoadTrigger(): void
	{
		$html = $this->renderer->buildButton(
			'/api/collections/blog/query',
			[
				'format' => 'html', 'template' => 'card', 'offset' => '0',
				'limit' => '10', 'mode' => 'append',
				'buttonId' => 'btn1', 'target' => '#feed',
			],
			'Load More',
			'',
			false,
			true,
		);

		$this->assertStringContainsString('hx-trigger="load, click"', $html);
	}

	public function testBuildButtonWithoutLoadTrigger(): void
	{
		$html = $this->renderer->buildButton(
			'/api/collections/blog/query',
			[
				'format' => 'html', 'template' => 'card', 'offset' => '0',
				'limit' => '10', 'mode' => 'append',
				'buttonId' => 'btn1', 'target' => '#feed',
			],
		);

		$this->assertStringContainsString('hx-trigger="click"', $html);
		$this->assertStringNotContainsString('hx-trigger="load', $html);
	}

	public function testBuildButtonWithCustomLabelAndClass(): void
	{
		$html = $this->renderer->buildButton(
			'/api/query',
			[
				'format' => 'html', 'template' => 'card', 'offset' => '0',
				'limit' => '10', 'mode' => 'append',
				'buttonId' => 'btn1', 'target' => '#feed',
			],
			'Show More Posts',
			'btn-primary',
		);

		$this->assertStringContainsString('Show More Posts', $html);
		$this->assertStringContainsString('cms-load-more btn-primary', $html);
	}

	public function testBuildButtonWithTransition(): void
	{
		$html = $this->renderer->buildButton(
			'/api/query',
			[
				'format' => 'html', 'template' => 'card', 'offset' => '0',
				'limit' => '10', 'mode' => 'append',
				'buttonId' => 'btn1', 'target' => '#feed',
			],
			'Load More',
			'',
			true,
		);

		$this->assertStringContainsString('hx-swap="beforeend transition:true"', $html);
	}

	public function testBuildButtonEscapesLabel(): void
	{
		$html = $this->renderer->buildButton(
			'/api/query',
			[
				'format' => 'html', 'template' => 'card', 'offset' => '0',
				'limit' => '10', 'mode' => 'append',
				'buttonId' => 'btn1', 'target' => '#feed',
			],
			'<script>alert("xss")</script>',
		);

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}

	// --- buildOobButton ---

	public function testBuildOobButtonWithMoreItemsReturnsOobButton(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 10, 0);

		$html = $this->renderer->buildOobButton('/api/collections/blog/query', $result, [
			'template' => 'blog/card',
			'limit'    => '10',
			'mode'     => 'append',
			'buttonId' => 'cms-lmb-abc12345',
			'target'   => '#blog-feed',
		]);

		$this->assertStringContainsString('<button', $html);
		$this->assertStringContainsString('id="cms-lmb-abc12345"', $html);
		$this->assertStringContainsString('hx-swap-oob="true"', $html);
		$this->assertStringContainsString('hx-target="#blog-feed"', $html);
		$this->assertStringContainsString('hx-swap="beforeend"', $html);
		$this->assertStringContainsString('hx-trigger="click"', $html);
		$this->assertStringContainsString('offset=10', $html);
	}

	public function testBuildOobButtonAlwaysUsesClickTrigger(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 10, 0);

		$html = $this->renderer->buildOobButton('/api/query', $result, [
			'template' => 'card',
			'limit'    => '10',
			'mode'     => 'append',
			'buttonId' => 'btn1',
			'target'   => '#feed',
		]);

		$this->assertStringContainsString('hx-trigger="click"', $html);
		$this->assertStringNotContainsString('hx-trigger="load', $html);
	}

	public function testBuildOobButtonWithNoMoreItemsReturnsDeleteElement(): void
	{
		$result = new QueryResult([['id' => '1']], 1, 10, 0);

		$html = $this->renderer->buildOobButton('/api/query', $result, [
			'template' => 'card',
			'limit'    => '10',
			'mode'     => 'append',
			'buttonId' => 'cms-lmb-abc12345',
			'target'   => '#feed',
		]);

		$this->assertStringContainsString('id="cms-lmb-abc12345"', $html);
		$this->assertStringContainsString('hx-swap-oob="delete"', $html);
		$this->assertStringNotContainsString('hx-get', $html);
	}

	public function testBuildOobButtonCarriesForwardParams(): void
	{
		$result = new QueryResult([['id' => '1']], 50, 10, 0);

		$html = $this->renderer->buildOobButton('/api/query', $result, [
			'template'    => 'blog/card',
			'limit'       => '10',
			'mode'        => 'append',
			'buttonId'    => 'btn1',
			'target'      => '#feed',
			'sort'        => '-date',
			'include'     => 'published:true',
			'buttonLabel' => 'Show More',
			'buttonClass' => 'btn-primary',
		]);

		$this->assertStringContainsString('sort=-date', $html);
		$this->assertStringContainsString('include=published%3Atrue', $html);
		$this->assertStringContainsString('buttonLabel=Show+More', $html);
		$this->assertStringContainsString('buttonClass=btn-primary', $html);
		$this->assertStringContainsString('Show More', $html);
		$this->assertStringContainsString('cms-load-more btn-primary', $html);
	}
}

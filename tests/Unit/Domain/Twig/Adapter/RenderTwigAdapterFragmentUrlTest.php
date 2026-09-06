<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * The fragment URL helpers are the public face of the HTML query endpoint:
 * what a template puts in hx-get / hx-post. They return raw URLs (Twig's
 * autoescape turns & into &amp; inside attributes), emit only the options
 * the caller set, and refuse a missing template early with the helper's
 * name in the message.
 */
final class RenderTwigAdapterFragmentUrlTest extends TestCase
{
	private RenderTwigAdapter $adapter;

	protected function setUp(): void
	{
		$config      = $this->createMock(Config::class);
		$config->api = '/tcms';

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new \Psr\Log\NullLogger());

		$this->adapter = new RenderTwigAdapter(
			$this->createMock(HtmxRenderer::class),
			$config,
			$this->createMock(DataTwigAdapter::class),
			$this->createMock(MediaTwigAdapter::class),
			$this->createMock(CollectionFetcher::class),
			$this->createMock(CollectionLister::class),
			$this->createMock(SchemaFetcher::class),
			$this->createMock(GridRenderer::class),
			$loggerFactory,
		);
	}

	public function testQueryUrlEmitsOnlyTheOptionsGiven(): void
	{
		$url = $this->adapter->queryUrl('blog', ['template' => 'blog/card', 'limit' => 12, 'search' => 'php']);

		expect($url)->toBe('/tcms/api/collections/blog/query?format=html&template=blog%2Fcard&limit=12&search=php');
	}

	public function testQueryUrlCarriesTheFilterKeys(): void
	{
		$url = $this->adapter->queryUrl('blog', ['template' => 'c', 'include' => 'published:true', 'exclude' => 'draft:true', 'sort' => '-date', 'offset' => 10, 'mode' => 'append']);

		expect($url)->toContain('include=published%3Atrue')
			->toContain('exclude=draft%3Atrue')
			->toContain('sort=-date')
			->toContain('offset=10')
			->toContain('mode=append');
	}

	public function testQueryUrlRequiresATemplate(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('queryUrl');

		$this->adapter->queryUrl('blog', ['limit' => 5]);
	}

	public function testViewQueryUrlTargetsTheDataViewEndpoint(): void
	{
		expect($this->adapter->viewQueryUrl('recent', ['template' => 'cards/item']))
			->toBe('/tcms/api/dataviews/recent/query?format=html&template=cards%2Fitem');
	}

	public function testObjectFragmentUrl(): void
	{
		expect($this->adapter->objectFragmentUrl('blog', 'hello world', ['template' => 'blog/quick']))
			->toBe('/tcms/api/collections/blog/hello%20world?format=html&template=blog%2Fquick');

		$this->expectException(\InvalidArgumentException::class);
		$this->adapter->objectFragmentUrl('blog', 'x');
	}

	public function testSaveUrlWithAndWithoutIdAndTemplate(): void
	{
		expect($this->adapter->saveUrl('contact'))->toBe('/tcms/api/collections/contact');
		expect($this->adapter->saveUrl('contact', ['template' => 'forms/thanks']))->toBe('/tcms/api/collections/contact?template=forms%2Fthanks');
		expect($this->adapter->saveUrl('posts', ['id' => 'hello', 'template' => 'posts/card']))->toBe('/tcms/api/collections/posts/hello?template=posts%2Fcard');
	}

	public function testIncrementUrlOmitsTheAmountWhenItIsOne(): void
	{
		expect($this->adapter->incrementUrl('posts', 'hello', 'likes'))->toBe('/tcms/api/collections/posts/hello/likes/increment');
		expect($this->adapter->incrementUrl('posts', 'hello', 'likes', 5))->toBe('/tcms/api/collections/posts/hello/likes/increment/5');
	}
}

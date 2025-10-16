<?php

namespace Tests\Unit\Action\Feed;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Feed\RssFeedAction;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Renderer\XmlRenderer;

final class RssFeedActionTest extends TestCase
{
	private RssFeedAction $action;
	private RssBuilder $rssBuilder;
	private XmlRenderer $xmlRenderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->rssBuilder  = $this->createMock(RssBuilder::class);
		$this->xmlRenderer = $this->createMock(XmlRenderer::class);
		$this->request     = $this->createMock(ServerRequestInterface::class);
		$this->response    = $this->createMock(ResponseInterface::class);

		$this->action = new RssFeedAction($this->xmlRenderer, $this->rssBuilder);
	}

	public function testBuildsRssFeedSuccessfully(): void
	{
		$args = ['collection' => 'blog'];

		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn('https://example.com/feed/blog');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getQueryParams')->willReturn(['limit' => '10']);

		$xml = '<?xml version="1.0"?><rss></rss>';

		$this->rssBuilder->expects($this->once())
			->method('setFieldMap')
			->with($this->callback(function ($params) {
				return $params['limit'] === '10' && $params['rssurl'] === 'https://example.com/feed/blog';
			}));

		$this->rssBuilder->expects($this->once())
			->method('buildFeed')
			->with('blog', $this->anything())
			->willReturn($xml);

		$this->xmlRenderer->expects($this->once())
			->method('xml')
			->with($this->response, $xml)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesCollectionToBuilder(): void
	{
		$args = ['collection' => 'news'];

		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn('https://example.com/feed/news');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getQueryParams')->willReturn([]);

		$this->rssBuilder->method('setFieldMap');

		$this->rssBuilder->expects($this->once())
			->method('buildFeed')
			->with('news', $this->anything())
			->willReturn('');

		$this->xmlRenderer->method('xml')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testAddsRssUrlToParams(): void
	{
		$args = ['collection' => 'blog'];

		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn('https://example.com/feed/blog?limit=5');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getQueryParams')->willReturn(['limit' => '5']);

		$this->rssBuilder->expects($this->once())
			->method('setFieldMap')
			->with($this->callback(function ($params) {
				return isset($params['rssurl']) && $params['rssurl'] === 'https://example.com/feed/blog?limit=5';
			}));

		$this->rssBuilder->method('buildFeed')->willReturn('');
		$this->xmlRenderer->method('xml')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesQueryParamsToBuilder(): void
	{
		$args = ['collection' => 'blog'];

		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn('https://example.com/feed');

		$params = [
			'title'       => 'My Blog',
			'description' => 'Blog Description',
			'limit'       => '20',
		];

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getQueryParams')->willReturn($params);

		$this->rssBuilder->expects($this->once())
			->method('setFieldMap')
			->with($this->callback(function ($p) use ($params) {
				return $p['title'] === 'My Blog'
					&& $p['description'] === 'Blog Description'
					&& $p['limit'] === '20'
					&& isset($p['rssurl']);
			}));

		$this->rssBuilder->method('buildFeed')->willReturn('');
		$this->xmlRenderer->method('xml')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsXmlResponse(): void
	{
		$args = ['collection' => 'blog'];

		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn('https://example.com/feed');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getQueryParams')->willReturn([]);

		$xml = '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>';

		$this->rssBuilder->method('setFieldMap');
		$this->rssBuilder->method('buildFeed')->willReturn($xml);

		$this->xmlRenderer->expects($this->once())
			->method('xml')
			->with($this->response, $xml)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testCallsBothSetFieldMapAndBuildFeed(): void
	{
		$args = ['collection' => 'blog'];

		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn('https://example.com/feed');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getQueryParams')->willReturn(['title' => 'Blog Feed']);

		$this->rssBuilder->expects($this->once())->method('setFieldMap');
		$this->rssBuilder->expects($this->once())->method('buildFeed')->willReturn('');

		$this->xmlRenderer->method('xml')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}

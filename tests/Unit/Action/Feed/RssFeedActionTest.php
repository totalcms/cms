<?php

namespace Tests\Unit\Action\Feed;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Feed\RssFeedAction;
use TotalCMS\Domain\Feed\Exception\FeedDisabledException;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Renderer\XmlRenderer;

final class RssFeedActionTest extends TestCase
{
	private RssFeedAction $action;
	private MockObject $rssBuilder;
	private MockObject $xmlRenderer;
	private MockObject $request;
	private MockObject $response;

	protected function setUp(): void
	{
		$this->rssBuilder  = $this->createMock(RssBuilder::class);
		$this->xmlRenderer = $this->createMock(XmlRenderer::class);
		$this->request     = $this->createMock(ServerRequestInterface::class);
		$this->response    = $this->createMock(ResponseInterface::class);

		$this->action = new RssFeedAction($this->xmlRenderer, $this->rssBuilder);
	}

	/** @param array<string,mixed> $query */
	private function request(array $query, string $url = 'https://example.com/feed/rss/blog'): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn($url);

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getQueryParams')->willReturn($query);
	}

	public function testRendersTheCollectionFeedAsXml(): void
	{
		$this->request(['limit' => '10']);

		$xml = '<?xml version="1.0"?><rss></rss>';

		$this->rssBuilder->expects($this->once())
			->method('buildFeed')
			->with('blog', ['limit' => '10', 'rssurl' => 'https://example.com/feed/rss/blog'])
			->willReturn($xml);

		$this->xmlRenderer->expects($this->once())
			->method('xml')
			->with($this->response, $xml)
			->willReturn($this->response);

		$this->assertSame($this->response, ($this->action)($this->request, $this->response, ['collection' => 'blog']));
	}

	public function testOnlyTheOverridableKeysReachTheBuilder(): void
	{
		$this->request([
			'name'        => 'News Only',
			'description' => 'Just the news',
			'include'     => 'category:news',
			'exclude'     => 'category:internal',
			'limit'       => '5',
			// Everything below used to pass straight through.
			'content'     => 'body',
			'title'       => 'headline',
			'link'        => 'https://evil.example/',
			'draft'       => 'anything',
			'hidden'      => 'anything',
			'enabled'     => '1',
			'rssurl'      => 'https://evil.example/feed',
			'bogus'       => 'x',
		]);

		$this->rssBuilder->expects($this->once())
			->method('buildFeed')
			->with('blog', [
				'name'        => 'News Only',
				'description' => 'Just the news',
				'include'     => 'category:news',
				'exclude'     => 'category:internal',
				'limit'       => '5',
				'rssurl'      => 'https://example.com/feed/rss/blog',
			])
			->willReturn('');
		$this->xmlRenderer->method('xml')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testNonStringValuesAreDropped(): void
	{
		$this->request(['include' => ['array'], 'limit' => '5']);

		$this->rssBuilder->expects($this->once())
			->method('buildFeed')
			->with('blog', ['limit' => '5', 'rssurl' => 'https://example.com/feed/rss/blog'])
			->willReturn('');
		$this->xmlRenderer->method('xml')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testACollectionWithoutAFeedAnswersNotFound(): void
	{
		$this->request([]);

		$this->rssBuilder->method('buildFeed')
			->willThrowException(new FeedDisabledException('RSS feed is not enabled for collection: members'));

		$notFound = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())->method('withStatus')->with(404)->willReturn($notFound);
		$this->xmlRenderer->expects($this->never())->method('xml');

		$this->assertSame($notFound, ($this->action)($this->request, $this->response, ['collection' => 'members']));
	}
}

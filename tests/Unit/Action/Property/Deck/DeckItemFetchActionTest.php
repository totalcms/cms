<?php

namespace Tests\Unit\Action\Property\Deck;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\Deck\DeckItemFetchAction;
use TotalCMS\Domain\Property\Service\DeckItemFetcher;
use TotalCMS\Renderer\JsonRenderer;

final class DeckItemFetchActionTest extends TestCase
{
	private DeckItemFetchAction $action;
	private DeckItemFetcher $deckItemFetcher;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->deckItemFetcher = $this->createMock(DeckItemFetcher::class);
		$this->renderer        = $this->createMock(JsonRenderer::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->response        = $this->createMock(ResponseInterface::class);

		$this->action = new DeckItemFetchAction($this->renderer, $this->deckItemFetcher);
	}

	public function testFetchesDeckItemSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
			'itemId'     => 'feature-1',
		];

		$item = [
			'id'    => 'feature-1',
			'title' => 'Feature Title',
		];

		$this->deckItemFetcher->expects($this->once())
			->method('fetchDeckItem')
			->with('products', 'product-1', 'features', 'feature-1')
			->willReturn($item);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $item)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testReturns404WhenItemNotFound(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
			'itemId'     => 'missing',
		];

		$this->deckItemFetcher->method('fetchDeckItem')->willReturn(null);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['error' => 'Deck item not found'], 404)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesAllArgsToFetcher(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'sections',
			'itemId'     => 'section-2',
		];

		$item = ['id' => 'section-2'];

		$this->deckItemFetcher->expects($this->once())
			->method('fetchDeckItem')
			->with('blog', 'post-5', 'sections', 'section-2')
			->willReturn($item);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testHandlesInvalidArgumentException(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
			'itemId'     => 'invalid',
		];

		$this->deckItemFetcher->method('fetchDeckItem')
			->willThrowException(new \InvalidArgumentException('Invalid property'));

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['error' => 'Invalid property'], 400)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testReturnsItemData(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'items',
			'itemId'     => 'item-1',
		];

		$item = [
			'id'      => 'item-1',
			'title'   => 'Test Item',
			'content' => 'Test Content',
		];

		$this->deckItemFetcher->method('fetchDeckItem')->willReturn($item);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $item)
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}

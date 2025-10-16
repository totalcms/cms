<?php

namespace Tests\Unit\Action\Property\Deck;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\Deck\DeckItemDeleteAction;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\DeckItemRemover;
use TotalCMS\Renderer\JsonRenderer;

final class DeckItemDeleteActionTest extends TestCase
{
	private DeckItemDeleteAction $action;
	private DeckItemRemover $deckItemRemover;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->deckItemRemover = $this->createMock(DeckItemRemover::class);
		$this->renderer        = $this->createMock(JsonRenderer::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->response        = $this->createMock(ResponseInterface::class);

		$this->action = new DeckItemDeleteAction($this->renderer, $this->deckItemRemover);
	}

	public function testDeletesDeckItemSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
			'itemId'     => 'feature-1',
		];

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemRemover->expects($this->once())
			->method('removeDeckItem')
			->with('products', 'product-1', 'features', 'feature-1')
			->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->anything())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesAllArgsToRemover(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'sections',
			'itemId'     => 'section-2',
		];

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemRemover->expects($this->once())
			->method('removeDeckItem')
			->with('blog', 'post-5', 'sections', 'section-2')
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

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

		$this->deckItemRemover->method('removeDeckItem')
			->willThrowException(new \InvalidArgumentException('Item not found'));

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['error' => 'Item not found'], 400)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testReturnsJsonItemWithTransformer(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'items',
			'itemId'     => 'item-1',
		];

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemRemover->method('removeDeckItem')->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}

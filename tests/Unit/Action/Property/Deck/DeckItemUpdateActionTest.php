<?php

namespace Tests\Unit\Action\Property\Deck;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\Deck\DeckItemUpdateAction;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\DeckItemUpdater;
use TotalCMS\Renderer\JsonRenderer;

final class DeckItemUpdateActionTest extends TestCase
{
	private DeckItemUpdateAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $deckItemUpdater;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->deckItemUpdater = $this->createMock(DeckItemUpdater::class);
		$this->renderer        = $this->createMock(JsonRenderer::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->response        = $this->createMock(ResponseInterface::class);

		$this->action = new DeckItemUpdateAction($this->renderer, $this->deckItemUpdater);
	}

	public function testUpdatesDeckItemSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
			'itemId'     => 'feature-1',
		];

		$data = ['title' => 'Updated Title'];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemUpdater->expects($this->once())
			->method('updateDeckItem')
			->with('products', 'product-1', 'features', 'feature-1', $data)
			->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->anything())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesAllArgsToUpdater(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'sections',
			'itemId'     => 'section-2',
		];

		$data = [
			'title'   => 'Section Title',
			'content' => 'Section Content',
		];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemUpdater->expects($this->once())
			->method('updateDeckItem')
			->with('blog', 'post-5', 'sections', 'section-2', $data)
			->willReturn($objectData);

		$this->renderer->method('jsonItem')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesBodyDataToUpdater(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'items',
			'itemId'     => 'item-1',
		];

		$data = [
			'field1' => 'value1',
			'field2' => 'value2',
		];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemUpdater->expects($this->once())
			->method('updateDeckItem')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $data)
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

		$this->request->method('getParsedBody')->willReturn([]);

		$this->deckItemUpdater->method('updateDeckItem')
			->willThrowException(new \InvalidArgumentException('Item not found'));

		$statusResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['error' => 'Item not found'])
			->willReturn($this->response);

		$this->response->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($statusResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($statusResponse, $result);
	}

	public function testReturnsJsonItemWithTransformer(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'items',
			'itemId'     => 'item-1',
		];

		$this->request->method('getParsedBody')->willReturn([]);

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemUpdater->method('updateDeckItem')->willReturn($objectData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}

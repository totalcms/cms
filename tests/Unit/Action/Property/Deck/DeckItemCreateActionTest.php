<?php

namespace Tests\Unit\Action\Property\Deck;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\Deck\DeckItemCreateAction;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\AutogenService;
use TotalCMS\Domain\Property\Service\DeckItemSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Renderer\JsonRenderer;

final class DeckItemCreateActionTest extends TestCase
{
	private DeckItemCreateAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $deckItemSaver;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $autogenIdService;
	private \PHPUnit\Framework\MockObject\MockObject $autogenService;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->deckItemSaver    = $this->createMock(DeckItemSaver::class);
		$this->renderer         = $this->createMock(JsonRenderer::class);
		$this->schemaFetcher    = $this->createMock(SchemaFetcher::class);
		$this->autogenIdService = $this->createMock(AutogenIdService::class);
		$this->autogenService   = $this->createMock(AutogenService::class);
		$this->request          = $this->createMock(ServerRequestInterface::class);
		$this->response         = $this->createMock(ResponseInterface::class);

		$this->action = new DeckItemCreateAction(
			$this->renderer,
			$this->deckItemSaver,
			$this->schemaFetcher,
			$this->autogenIdService,
			$this->autogenService
		);
	}

	public function testCreatesDeckItemSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
		];

		$data = [
			'id'    => 'feature-1',
			'title' => 'Feature Title',
		];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemSaver->expects($this->once())
			->method('saveDeckItem')
			->with('products', 'product-1', 'features', 'feature-1', $data)
			->willReturn($objectData);

		$response201 = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->anything())
			->willReturn($response201);

		$response201->expects($this->once())
			->method('withStatus')
			->with(201)
			->willReturn($response201);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($response201, $result);
	}

	public function testReturns400WhenIdMissing(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
		];

		$data = ['title' => 'Feature Title']; // Missing 'id'

		$this->request->method('getParsedBody')->willReturn($data);

		$this->deckItemSaver->expects($this->never())->method('saveDeckItem');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$jsonResponse->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($this->response);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['error' => 'Deck item id is required'])
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testReturns400WhenIdEmpty(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
		];

		$data = ['id' => '', 'title' => 'Feature']; // Empty id

		$this->request->method('getParsedBody')->willReturn($data);

		$this->deckItemSaver->expects($this->never())->method('saveDeckItem');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$jsonResponse->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($this->response);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['error' => 'Deck item id is required'])
			->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesAllDataToSaver(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'sections',
		];

		$data = [
			'id'      => 'section-1',
			'title'   => 'Section',
			'content' => 'Content',
		];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemSaver->expects($this->once())
			->method('saveDeckItem')
			->with('blog', 'post-5', 'sections', 'section-1', $data)
			->willReturn($objectData);

		$response201 = $this->createMock(ResponseInterface::class);
		$response201->method('withStatus')->willReturn($response201);

		$this->renderer->method('jsonItem')->willReturn($response201);

		($this->action)($this->request, $this->response, $args);
	}

	public function testHandlesInvalidArgumentException(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'features',
		];

		$data = ['id' => 'feature-1'];

		$this->request->method('getParsedBody')->willReturn($data);

		$this->deckItemSaver->method('saveDeckItem')
			->willThrowException(new \InvalidArgumentException('Invalid deck item'));

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$jsonResponse->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($this->response);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['error' => 'Invalid deck item'])
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testReturnsJsonItemWithTransformer(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'items',
		];

		$data = ['id' => 'item-1'];

		$this->request->method('getParsedBody')->willReturn($data);

		$objectData = $this->createMock(ObjectData::class);

		$this->deckItemSaver->method('saveDeckItem')->willReturn($objectData);

		$response201 = $this->createMock(ResponseInterface::class);
		$response201->method('withStatus')->willReturn($response201);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $objectData, $this->isInstanceOf(\TotalCMS\Transformer\ObjectMetaTransformer::class))
			->willReturn($response201);

		($this->action)($this->request, $this->response, $args);
	}
}

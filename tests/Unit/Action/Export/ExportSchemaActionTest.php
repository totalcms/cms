<?php

namespace Tests\Unit\Action\Export;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Export\ExportSchemaAction;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class ExportSchemaActionTest extends TestCase
{
	private ExportSchemaAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);
		$this->response      = $this->createMock(ResponseInterface::class);

		$this->action = new ExportSchemaAction($this->schemaFetcher);
	}

	public function testExportsSchemaSuccessfully(): void
	{
		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'blog';
		$schemaData->method('toJson')->willReturn('{"id":"blog","name":"Blog Schema"}');

		$this->schemaFetcher->expects($this->once())
			->method('fetchSchema')
			->with('blog')
			->willReturn($schemaData);

		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnSelf();

		$this->response->expects($this->once())
			->method('withBody')
			->willReturnSelf();

		$result = ($this->action)($this->request, $this->response, ['schema' => 'blog']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testSetsJsonContentType(): void
	{
		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'test';
		$schemaData->method('toJson')->willReturn('{}');

		$this->schemaFetcher->method('fetchSchema')->willReturn($schemaData);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['schema' => 'test']);

		$this->assertTrue(true);
	}

	public function testSetsContentDispositionWithSchemaId(): void
	{
		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'product';
		$schemaData->method('toJson')->willReturn('{}');

		$this->schemaFetcher->method('fetchSchema')->willReturn($schemaData);

		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnCallback(function ($name, string $value): ResponseInterface {
				if ($name === 'Content-Disposition') {
					$this->assertStringContainsString('attachment', $value);
					$this->assertStringContainsString('schema-product.json', $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['schema' => 'product']);
	}

	public function testUsesSchemaToJsonMethod(): void
	{
		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'test';
		$schemaData->expects($this->once())
			->method('toJson')
			->willReturn('{"test":"data"}');

		$this->schemaFetcher->method('fetchSchema')->willReturn($schemaData);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['schema' => 'test']);
	}

	public function testReturnsResponseWithSchemaBody(): void
	{
		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'test';
		$schemaData->method('toJson')->willReturn('{"id":"test"}');

		$this->schemaFetcher->method('fetchSchema')->willReturn($schemaData);

		$this->response->method('withHeader')->willReturnSelf();

		$responseWithBody = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withBody')
			->with($this->isInstanceOf(StreamInterface::class))
			->willReturn($responseWithBody);

		$result = ($this->action)($this->request, $this->response, ['schema' => 'test']);

		$this->assertSame($responseWithBody, $result);
	}

	public function testFetchesCorrectSchema(): void
	{
		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'custom-schema';
		$schemaData->method('toJson')->willReturn('{}');

		$this->schemaFetcher->expects($this->once())
			->method('fetchSchema')
			->with('custom-schema')
			->willReturn($schemaData);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response, ['schema' => 'custom-schema']);
	}
}

<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Import\ImportFactoryAction;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Renderer\JsonRenderer;

final class ImportFactoryActionTest extends TestCase
{
	private ImportFactoryAction $action;
	private FactoryImporter $importer;
	private JobQueuer $jobQueuer;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->importer  = $this->createMock(FactoryImporter::class);
		$this->jobQueuer = $this->createMock(JobQueuer::class);
		$this->renderer  = $this->createMock(JsonRenderer::class);
		$this->request   = $this->createMock(ServerRequestInterface::class);
		$this->response  = $this->createMock(ResponseInterface::class);

		$this->action = new ImportFactoryAction($this->renderer, $this->importer, $this->jobQueuer);
	}

	public function testGeneratesFactoryDataDirectly(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{"field1": "value1"}');

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getBody')->willReturn($body);

		$this->importer->expects($this->once())
			->method('import')
			->with('products', 1, ['field1' => 'value1'])
			->willReturn(1);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['import_count' => 1])
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'products']);

		$this->assertSame($this->response, $result);
	}

	public function testSupportsQuantityFromQueryParams(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{}');

		$this->request->method('getQueryParams')->willReturn(['fqty' => '10']);
		$this->request->method('getBody')->willReturn($body);

		$this->importer->expects($this->once())
			->method('import')
			->with('products', 10, [])
			->willReturn(10);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testSupportsQuantityFromBody(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{"fqty": 5, "field1": "value"}');

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getBody')->willReturn($body);

		$this->importer->expects($this->once())
			->method('import')
			->with('products', 5, ['field1' => 'value'])
			->willReturn(5);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testQueuesJobForLargeQuantity(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{"fqty": 100}');

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getBody')->willReturn($body);

		$this->jobQueuer->expects($this->once())
			->method('queueFactory')
			->with('products', 100, [])
			->willReturn('job-123');

		$this->importer->expects($this->never())
			->method('import');

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return $data['job_queued'] === true
					&& $data['job_id'] === 'job-123'
					&& $data['quantity'] === 100;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testQueuesJobWhenExplicitlyRequested(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{"fqty": 10, "queue": true}');

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getBody')->willReturn($body);

		$this->jobQueuer->expects($this->once())
			->method('queueFactory')
			->with('products', 10, [])
			->willReturn('job-456');

		$this->importer->expects($this->never())
			->method('import');

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testRemovesFqtyFromRules(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{"fqty": 3, "field1": "value", "field2": "test"}');

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getBody')->willReturn($body);

		$this->importer->expects($this->once())
			->method('import')
			->with('products', 3, $this->callback(function ($rules) {
				return !isset($rules['fqty'])
					&& isset($rules['field1'])
					&& isset($rules['field2']);
			}))
			->willReturn(3);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testRemovesQueueFromRules(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{"queue": false, "field1": "value"}');

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getBody')->willReturn($body);

		$this->importer->expects($this->once())
			->method('import')
			->with('products', 1, $this->callback(function ($rules) {
				return !isset($rules['queue']) && isset($rules['field1']);
			}))
			->willReturn(1);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testDefaultsToQuantityOne(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{"field": "value"}');

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getBody')->willReturn($body);

		$this->importer->expects($this->once())
			->method('import')
			->with('products', 1, ['field' => 'value'])
			->willReturn(1);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testReturnsJobQueuedResponse(): void
	{
		$body = $this->createMock(StreamInterface::class);
		$body->method('__toString')->willReturn('{"fqty": 200}');

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getBody')->willReturn($body);

		$this->jobQueuer->method('queueFactory')->willReturn('job-789');

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return isset($data['job_queued'])
					&& isset($data['job_id'])
					&& isset($data['quantity'])
					&& isset($data['message'])
					&& str_contains($data['message'], '200 objects');
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}
}

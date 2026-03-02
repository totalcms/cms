<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Mailer;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Mailer\BulkObjectOptionsAction;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Renderer\JsonRenderer;

final class BulkObjectOptionsActionTest extends TestCase
{
	private BulkObjectOptionsAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $indexFilter;
	private JsonRenderer $renderer;

	protected function setUp(): void
	{
		$this->indexFilter = $this->createMock(IndexFilter::class);
		$this->renderer    = new JsonRenderer();

		$this->action = new BulkObjectOptionsAction(
			$this->indexFilter,
			$this->renderer,
		);
	}

	public function testReturnsEmptyArrayWhenNoCollection(): void
	{
		$request  = $this->createRequest([]);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$this->assertSame([], $this->decodeJson($result));
	}

	public function testReturnsEmptyArrayWhenCollectionIsEmpty(): void
	{
		$request  = $this->createRequest(['bulkCollection' => '']);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$this->assertSame([], $this->decodeJson($result));
	}

	public function testReturnsEmptyArrayWhenNoMatchingObjects(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([]);

		$request  = $this->createRequest(['bulkCollection' => 'subscribers']);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$this->assertSame([], $this->decodeJson($result));
	}

	public function testReturnsEmptyArrayOnException(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')
			->willThrowException(new \Exception('Index error'));

		$request  = $this->createRequest(['bulkCollection' => 'subscribers']);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$this->assertSame([], $this->decodeJson($result));
	}

	public function testReturnsChoicesWithIdOnly(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'obj-1'],
			['id' => 'obj-2'],
		]);

		$request  = $this->createRequest(['bulkCollection' => 'subscribers']);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);
		$data   = $this->decodeJson($result);

		$this->assertCount(2, $data);
		$this->assertSame('obj-1', $data[0]['value']);
		$this->assertSame('obj-1', $data[0]['label']);
		$this->assertSame('obj-2', $data[1]['value']);
	}

	public function testDetectsNameLabelField(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'obj-1', 'name' => 'Alice'],
		]);

		$request  = $this->createRequest(['bulkCollection' => 'subscribers']);
		$response = $this->createResponse();

		$data = $this->decodeJson(($this->action)($request, $response));

		$this->assertSame('obj-1 - Alice', $data[0]['label']);
	}

	public function testDetectsTitleLabelField(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'post-1', 'title' => 'My Post'],
		]);

		$request  = $this->createRequest(['bulkCollection' => 'blog']);
		$response = $this->createResponse();

		$data = $this->decodeJson(($this->action)($request, $response));

		$this->assertSame('post-1 - My Post', $data[0]['label']);
	}

	public function testDetectsEmailLabelField(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'sub-1', 'email' => 'user@example.com'],
		]);

		$request  = $this->createRequest(['bulkCollection' => 'subscribers']);
		$response = $this->createResponse();

		$data = $this->decodeJson(($this->action)($request, $response));

		$this->assertSame('sub-1 - user@example.com', $data[0]['label']);
	}

	public function testPassesFiltersToIndexFilter(): void
	{
		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->with(
				'subscribers',
				$this->callback(fn (array $options): bool => $options['include'] === 'status:active'
					&& $options['exclude'] === 'type:draft')
			)
			->willReturn([]);

		$request = $this->createRequest([
			'bulkCollection' => 'subscribers',
			'bulkInclude'    => 'status:active',
			'bulkExclude'    => 'type:draft',
		]);
		$response = $this->createResponse();

		($this->action)($request, $response);
	}

	public function testOmitsEmptyFilters(): void
	{
		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->with('subscribers', [])
			->willReturn([]);

		$request  = $this->createRequest([
			'bulkCollection' => 'subscribers',
			'bulkInclude'    => '',
			'bulkExclude'    => '',
		]);
		$response = $this->createResponse();

		($this->action)($request, $response);
	}

	public function testSkipsObjectsWithEmptyIds(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'obj-1', 'name' => 'Alice'],
			['id' => '', 'name' => 'NoId'],
			['name' => 'MissingId'],
		]);

		$request  = $this->createRequest(['bulkCollection' => 'subscribers']);
		$response = $this->createResponse();

		$data = $this->decodeJson(($this->action)($request, $response));

		$this->assertCount(1, $data);
		$this->assertSame('obj-1', $data[0]['value']);
	}

	public function testResponseHasJsonContentType(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([]);

		$request  = $this->createRequest(['bulkCollection' => 'subscribers']);
		$response = $this->createResponse();

		$result = ($this->action)($request, $response);

		$this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
	}

	// ── Helpers ──

	/** @param array<string,string> $queryParams */
	private function createRequest(array $queryParams): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn($queryParams);

		return $request;
	}

	private function createResponse(): ResponseInterface
	{
		$stream = $this->createMock(StreamInterface::class);
		$buffer = '';

		$stream->method('write')->willReturnCallback(function (string $data) use (&$buffer): int {
			$buffer .= $data;

			return strlen($data);
		});

		$stream->method('__toString')->willReturnCallback(function () use (&$buffer): string {
			return $buffer;
		});

		$response = $this->createMock(ResponseInterface::class);
		$response->method('getBody')->willReturn($stream);
		$response->method('withHeader')->willReturnCallback(
			function (string $name, string $value) use (&$buffer): ResponseInterface {
				$newResponse = $this->createMock(ResponseInterface::class);
				$stream      = $this->createMock(StreamInterface::class);

				$stream->method('write')->willReturnCallback(function (string $data) use (&$buffer): int {
					$buffer .= $data;

					return strlen($data);
				});
				$stream->method('__toString')->willReturnCallback(function () use (&$buffer): string {
					return $buffer;
				});

				$newResponse->method('getBody')->willReturn($stream);
				$newResponse->method('getHeaderLine')->willReturnCallback(
					fn (string $headerName): string => strtolower($headerName) === strtolower($name) ? $value : ''
				);

				return $newResponse;
			}
		);

		return $response;
	}

	/** @return array<int,array{value:string,label:string}> */
	private function decodeJson(ResponseInterface $response): array
	{
		$body = (string)$response->getBody();

		return (array)json_decode($body, true);
	}
}

<?php

namespace Tests\Unit\Action\Admin\ApiKey;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\ApiKey\ApiKeyDeleteAction;
use TotalCMS\Domain\ApiKey\Service\ApiKeyDeleter;
use TotalCMS\Renderer\JsonRenderer;

final class ApiKeyDeleteActionTest extends TestCase
{
	private ApiKeyDeleteAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $apiKeyDeleter;
	private \PHPUnit\Framework\MockObject\MockObject $jsonRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->apiKeyDeleter = $this->createMock(ApiKeyDeleter::class);
		$this->jsonRenderer  = $this->createMock(JsonRenderer::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);
		$this->response      = $this->createMock(ResponseInterface::class);

		$this->action = new ApiKeyDeleteAction(
			$this->apiKeyDeleter,
			$this->jsonRenderer
		);
	}

	public function testDeletesApiKeySuccessfully(): void
	{
		$args = ['id' => 'test-key-id'];

		$this->apiKeyDeleter->expects($this->once())
			->method('deleteKey')
			->with('test-key-id')
			->willReturn(true);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($this->response, [
				'success' => true,
				'message' => 'API key deleted successfully',
			])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testReturns404WhenApiKeyNotFound(): void
	{
		$args = ['id' => 'nonexistent-key'];

		$this->apiKeyDeleter->expects($this->once())
			->method('deleteKey')
			->with('nonexistent-key')
			->willReturn(false);

		$notFoundResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(404)
			->willReturn($notFoundResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($notFoundResponse, [
				'error' => [
					'message' => 'API key not found',
				],
			])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testReturns400WhenIdIsMissing(): void
	{
		$args = [];

		$this->apiKeyDeleter->expects($this->never())
			->method('deleteKey');

		$badRequestResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($badRequestResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($badRequestResponse, [
				'error' => [
					'message' => 'API key ID is required',
				],
			])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testReturns400WhenIdIsEmpty(): void
	{
		$args = ['id' => ''];

		$this->apiKeyDeleter->expects($this->never())
			->method('deleteKey');

		$badRequestResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($badRequestResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($badRequestResponse, [
				'error' => [
					'message' => 'API key ID is required',
				],
			])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testHandlesSpecialCharactersInId(): void
	{
		$args = ['id' => 'key-with-dashes-and_underscores_123'];

		$this->apiKeyDeleter->expects($this->once())
			->method('deleteKey')
			->with('key-with-dashes-and_underscores_123')
			->willReturn(true);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return $data['success'] === true && $data['message'] === 'API key deleted successfully';
			}))
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($jsonResponse, $actualResponse);
	}
}

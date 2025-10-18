<?php

namespace Tests\Unit\Action\Admin\ApiKey;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\ApiKey\ApiKeyCreateAction;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Renderer\JsonRenderer;

final class ApiKeyCreateActionTest extends TestCase
{
	private ApiKeyCreateAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $apiKeyCreator;
	private \PHPUnit\Framework\MockObject\MockObject $jsonRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->apiKeyCreator = $this->createMock(ApiKeyCreator::class);
		$this->jsonRenderer  = $this->createMock(JsonRenderer::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);
		$this->response      = $this->createMock(ResponseInterface::class);

		$this->action = new ApiKeyCreateAction(
			$this->apiKeyCreator,
			$this->jsonRenderer
		);
	}

	public function testCreatesApiKeySuccessfully(): void
	{
		$requestData = [
			'name'          => 'Test API Key',
			'endpoint-type' => 'specific',
			'methods'       => ['GET', 'POST'],
			'paths'         => ['/collections/blog'],
		];

		$expectedScopes = [
			'methods' => ['GET', 'POST'],
			'paths'   => ['/collections/blog'],
		];

		$apiKeyData = new ApiKeyData([
			'id'      => 'test-key-id',
			'name'    => 'Test API Key',
			'key'     => 'tcms_1234567890abcdef1234567890abcdef',
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => $expectedScopes,
		]);

		$this->request->expects($this->once())
			->method('getParsedBody')
			->willReturn($requestData);

		$this->apiKeyCreator->expects($this->once())
			->method('createApiKey')
			->with('Test API Key', $expectedScopes)
			->willReturn($apiKeyData);

		$createdResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(201)
			->willReturn($createdResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($createdResponse, [
				'success' => true,
				'message' => 'API key created successfully',
				'apiKey'  => $apiKeyData->toArray(),
			])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testCreatesApiKeyWithUniversalAccess(): void
	{
		$requestData = [
			'name'          => 'Universal API Key',
			'endpoint-type' => 'all',
			'methods'       => ['GET', 'POST', 'PUT', 'DELETE'],
			'paths'         => ['/collections/blog'], // Should be ignored
		];

		$expectedScopes = [
			'methods' => ['GET', 'POST', 'PUT', 'DELETE'],
			'paths'   => ['*'], // Universal access
		];

		$apiKeyData = new ApiKeyData([
			'id'      => 'universal-key',
			'name'    => 'Universal API Key',
			'key'     => 'tcms_abcdef1234567890abcdef1234567890',
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => $expectedScopes,
		]);

		$this->request->expects($this->once())
			->method('getParsedBody')
			->willReturn($requestData);

		$this->apiKeyCreator->expects($this->once())
			->method('createApiKey')
			->with('Universal API Key', $expectedScopes)
			->willReturn($apiKeyData);

		$createdResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(201)
			->willReturn($createdResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($createdResponse, $this->callback(fn ($data): bool => $data['success'] === true
					&& $data['message'] === 'API key created successfully'
					&& isset($data['apiKey'])))
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testHandlesEmptyRequestBody(): void
	{
		$requestData = [];

		$expectedScopes = [
			'methods' => [],
			'paths'   => [],
		];

		$apiKeyData = new ApiKeyData([
			'id'      => 'empty-key',
			'name'    => '',
			'key'     => 'tcms_empty1234567890abcdef1234567890',
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => $expectedScopes,
		]);

		$this->request->expects($this->once())
			->method('getParsedBody')
			->willReturn($requestData);

		$this->apiKeyCreator->expects($this->once())
			->method('createApiKey')
			->with('', $expectedScopes)
			->willReturn($apiKeyData);

		$createdResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(201)
			->willReturn($createdResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testReturns400OnInvalidArgumentException(): void
	{
		$requestData = [
			'name'          => '',
			'endpoint-type' => 'specific',
			'methods'       => [],
			'paths'         => [],
		];

		$this->request->expects($this->once())
			->method('getParsedBody')
			->willReturn($requestData);

		$this->apiKeyCreator->expects($this->once())
			->method('createApiKey')
			->willThrowException(new \InvalidArgumentException('Name is required'));

		$badRequestResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($badRequestResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($badRequestResponse, [
				'error' => ['message' => 'Name is required'],
			])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testHandlesMultiplePaths(): void
	{
		$requestData = [
			'name'          => 'Multi-Path Key',
			'endpoint-type' => 'specific',
			'methods'       => ['GET'],
			'paths'         => ['/collections/blog', '/collections/news', '/collections/events'],
		];

		$expectedScopes = [
			'methods' => ['GET'],
			'paths'   => ['/collections/blog', '/collections/news', '/collections/events'],
		];

		$apiKeyData = new ApiKeyData([
			'id'      => 'multi-path-key',
			'name'    => 'Multi-Path Key',
			'key'     => 'tcms_multipath1234567890abcdef1234',
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => $expectedScopes,
		]);

		$this->request->expects($this->once())
			->method('getParsedBody')
			->willReturn($requestData);

		$this->apiKeyCreator->expects($this->once())
			->method('createApiKey')
			->with('Multi-Path Key', $expectedScopes)
			->willReturn($apiKeyData);

		$createdResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(201)
			->willReturn($createdResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}
}

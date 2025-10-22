<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Middleware\Auth\ApiKeyAuthMiddleware;
use TotalCMS\Renderer\JsonRenderer;

final class ApiKeyAuthMiddlewareTest extends TestCase
{
	private ApiKeyAuthMiddleware $middleware;
	private \PHPUnit\Framework\MockObject\MockObject $authenticator;
	private \PHPUnit\Framework\MockObject\MockObject $jsonRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $responseFactory;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->authenticator   = $this->createMock(ApiKeyAuthenticator::class);
		$this->jsonRenderer    = $this->createMock(JsonRenderer::class);
		$this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->handler         = $this->createMock(RequestHandlerInterface::class);
		$this->response        = $this->createMock(ResponseInterface::class);

		$this->middleware = new ApiKeyAuthMiddleware(
			$this->authenticator,
			$this->jsonRenderer,
			$this->responseFactory
		);
	}

	public function testReturns401WhenNoApiKeyHeader(): void
	{
		$this->authenticator->expects($this->once())
			->method('hasApiKeyHeader')
			->with($this->request)
			->willReturn(false);

		$unauthorizedResponse = $this->createMock(ResponseInterface::class);
		$this->responseFactory->expects($this->once())
			->method('createResponse')
			->willReturn($this->response);

		$this->response->expects($this->once())
			->method('withStatus')
			->with(401)
			->willReturn($unauthorizedResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($unauthorizedResponse, [
				'error' => ['message' => 'API key required. Provide it in the Authorization header as "Bearer {key}" or in the X-API-Key header'],
			])
			->willReturn($jsonResponse);

		$this->handler->expects($this->never())
			->method('handle');

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns401WhenApiKeyIsInvalid(): void
	{
		$this->authenticator->expects($this->once())
			->method('hasApiKeyHeader')
			->with($this->request)
			->willReturn(true);

		$this->authenticator->expects($this->once())
			->method('authenticate')
			->with($this->request)
			->willReturn(null);

		$unauthorizedResponse = $this->createMock(ResponseInterface::class);
		$this->responseFactory->expects($this->once())
			->method('createResponse')
			->willReturn($this->response);

		$this->response->expects($this->once())
			->method('withStatus')
			->with(401)
			->willReturn($unauthorizedResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($unauthorizedResponse, [
				'error' => ['message' => 'Invalid API key or insufficient permissions'],
			])
			->willReturn($jsonResponse);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($jsonResponse, $result);
	}

	public function testAllowsRequestWithValidApiKey(): void
	{
		$apiKeyData = new ApiKeyData([
			'id'      => 'key-123',
			'name'    => 'Test Key',
			'key'     => 'tcms_validkey123',
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/blog'],
			],
		]);

		$this->authenticator->expects($this->once())
			->method('hasApiKeyHeader')
			->with($this->request)
			->willReturn(true);

		$this->authenticator->expects($this->once())
			->method('authenticate')
			->with($this->request)
			->willReturn($apiKeyData);

		$requestWithAttribute = $this->createMock(ServerRequestInterface::class);
		$this->request->expects($this->once())
			->method('withAttribute')
			->with('apiKey', $apiKeyData)
			->willReturn($requestWithAttribute);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())
			->method('handle')
			->with($requestWithAttribute)
			->willReturn($handlerResponse);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($handlerResponse, $result);
	}
}

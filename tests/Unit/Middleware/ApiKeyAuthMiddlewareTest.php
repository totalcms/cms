<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Middleware\ApiKeyAuthMiddleware;
use TotalCMS\Renderer\JsonRenderer;

final class ApiKeyAuthMiddlewareTest extends TestCase
{
	private ApiKeyAuthMiddleware $middleware;
	private \PHPUnit\Framework\MockObject\MockObject $apiKeyFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $jsonRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $responseFactory;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->apiKeyFetcher   = $this->createMock(ApiKeyFetcher::class);
		$this->jsonRenderer    = $this->createMock(JsonRenderer::class);
		$this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->handler         = $this->createMock(RequestHandlerInterface::class);
		$this->response        = $this->createMock(ResponseInterface::class);

		$this->middleware = new ApiKeyAuthMiddleware(
			$this->apiKeyFetcher,
			$this->jsonRenderer,
			$this->responseFactory
		);
	}

	public function testReturns401WhenNoAuthorizationHeader(): void
	{
		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('');

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
				'error' => ['message' => 'API key required. Provide it in the Authorization header as "Bearer {key}"'],
			])
			->willReturn($jsonResponse);

		$this->handler->expects($this->never())
			->method('handle');

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns401WhenAuthorizationHeaderDoesNotStartWithBearer(): void
	{
		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Basic sometoken');

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
				'error' => ['message' => 'Invalid authorization format. Use "Bearer {key}"'],
			])
			->willReturn($jsonResponse);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns401WhenApiKeyIsEmpty(): void
	{
		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer ');

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
				'error' => ['message' => 'API key cannot be empty'],
			])
			->willReturn($jsonResponse);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns401WhenApiKeyIsInvalid(): void
	{
		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer invalid-key');

		$this->request->expects($this->once())
			->method('getMethod')
			->willReturn('GET');

		$uri = $this->createMock(UriInterface::class);
		$uri->expects($this->once())
			->method('getPath')
			->willReturn('/collections/blog');

		$this->request->expects($this->once())
			->method('getUri')
			->willReturn($uri);

		$this->request->expects($this->once())
			->method('getAttribute')
			->with('basePath', '')
			->willReturn('');

		$this->apiKeyFetcher->expects($this->once())
			->method('validateKey')
			->with('invalid-key', 'GET', '/collections/blog')
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
		$apiKey     = 'tcms_validkey123';
		$apiKeyData = new ApiKeyData([
			'id'      => 'key-123',
			'name'    => 'Test Key',
			'key'     => $apiKey,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/blog'],
			],
		]);

		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer ' . $apiKey);

		$this->request->expects($this->once())
			->method('getMethod')
			->willReturn('GET');

		$uri = $this->createMock(UriInterface::class);
		$uri->expects($this->once())
			->method('getPath')
			->willReturn('/collections/blog');

		$this->request->expects($this->once())
			->method('getUri')
			->willReturn($uri);

		$this->request->expects($this->once())
			->method('getAttribute')
			->with('basePath', '')
			->willReturn('');

		$this->apiKeyFetcher->expects($this->once())
			->method('validateKey')
			->with($apiKey, 'GET', '/collections/blog')
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

	public function testHandlesBasePathCorrectly(): void
	{
		$apiKey     = 'tcms_validkey123';
		$apiKeyData = new ApiKeyData([
			'id'      => 'key-123',
			'name'    => 'Test Key',
			'key'     => $apiKey,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => [
				'methods' => ['POST'],
				'paths'   => ['/collections/blog'],
			],
		]);

		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer ' . $apiKey);

		$this->request->expects($this->once())
			->method('getMethod')
			->willReturn('POST');

		$uri = $this->createMock(UriInterface::class);
		$uri->expects($this->once())
			->method('getPath')
			->willReturn('/rw_common/plugins/stacks/tcms/collections/blog');

		$this->request->expects($this->once())
			->method('getUri')
			->willReturn($uri);

		$this->request->expects($this->once())
			->method('getAttribute')
			->with('basePath', '')
			->willReturn('/rw_common/plugins/stacks/tcms');

		// Should validate with the path after stripping basePath
		$this->apiKeyFetcher->expects($this->once())
			->method('validateKey')
			->with($apiKey, 'POST', '/collections/blog')
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

	public function testSupportsMultipleMethods(): void
	{
		$apiKey     = 'tcms_validkey123';
		$apiKeyData = new ApiKeyData([
			'id'      => 'key-123',
			'name'    => 'Test Key',
			'key'     => $apiKey,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => [
				'methods' => ['GET', 'POST', 'PUT', 'DELETE'],
				'paths'   => ['*'],
			],
		]);

		$this->request->expects($this->once())
			->method('getHeaderLine')
			->with('Authorization')
			->willReturn('Bearer ' . $apiKey);

		$this->request->expects($this->once())
			->method('getMethod')
			->willReturn('DELETE');

		$uri = $this->createMock(UriInterface::class);
		$uri->expects($this->once())
			->method('getPath')
			->willReturn('/collections/blog/123');

		$this->request->expects($this->once())
			->method('getUri')
			->willReturn($uri);

		$this->request->expects($this->once())
			->method('getAttribute')
			->with('basePath', '')
			->willReturn('');

		$this->apiKeyFetcher->expects($this->once())
			->method('validateKey')
			->with($apiKey, 'DELETE', '/collections/blog/123')
			->willReturn($apiKeyData);

		$requestWithAttribute = $this->createMock(ServerRequestInterface::class);
		$this->request->expects($this->once())
			->method('withAttribute')
			->willReturn($requestWithAttribute);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($handlerResponse);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($handlerResponse, $result);
	}
}

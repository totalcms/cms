<?php

namespace Tests\Unit\Action;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TotalCMS\Action\PreflightAction;

final class PreflightActionTest extends TestCase
{
	private PreflightAction $action;
	private Psr17Factory $factory;

	protected function setUp(): void
	{
		$this->action  = new PreflightAction();
		$this->factory = new Psr17Factory();
	}

	public function testInvokeReturnsUnmodifiedResponse(): void
	{
		$request  = $this->factory->createServerRequest('OPTIONS', '/');
		$response = $this->factory->createResponse(200);

		$result = ($this->action)($request, $response);

		$this->assertSame($response, $result);
	}

	public function testInvokePreservesResponseStatus(): void
	{
		$request  = $this->factory->createServerRequest('OPTIONS', '/api/collections');
		$response = $this->factory->createResponse(204);

		$result = ($this->action)($request, $response);

		$this->assertSame(204, $result->getStatusCode());
	}

	public function testInvokeWorksWithDifferentMethods(): void
	{
		$methods = ['OPTIONS', 'GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

		foreach ($methods as $method) {
			$request  = $this->factory->createServerRequest($method, '/');
			$response = $this->factory->createResponse();

			$result = ($this->action)($request, $response);

			$this->assertSame($response, $result);
		}
	}

	public function testInvokePreservesResponseHeaders(): void
	{
		$request  = $this->factory->createServerRequest('OPTIONS', '/');
		$response = $this->factory->createResponse()
			->withHeader('Access-Control-Allow-Origin', '*')
			->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

		$result = ($this->action)($request, $response);

		$this->assertSame('*', $result->getHeaderLine('Access-Control-Allow-Origin'));
		$this->assertSame('GET, POST, OPTIONS', $result->getHeaderLine('Access-Control-Allow-Methods'));
	}
}

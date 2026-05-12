<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Builder;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Builder\BuilderReorderAction;
use TotalCMS\Domain\Builder\Service\BuilderReorderService;

final class BuilderReorderActionTest extends TestCase
{
	private BuilderReorderService&MockObject $service;
	private BuilderReorderAction $action;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->service = $this->createMock(BuilderReorderService::class);
		$this->action  = new BuilderReorderAction($this->service);

		$factory        = new Psr17Factory();
		$this->response = $factory->createResponse();
	}

	public function testReturns200OnSuccessWithCount(): void
	{
		$this->service->method('applyTree')->willReturn(['ok' => true, 'count' => 5]);

		$response = ($this->action)($this->makeRequest(['tree' => '[]']), $this->response);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
		$this->assertSame(['ok' => true, 'count' => 5], $this->bodyAsJson($response));
	}

	public function testReturns422OnGenericError(): void
	{
		$this->service->method('applyTree')->willReturn(['ok' => false, 'error' => 'Missing or invalid tree']);

		$response = ($this->action)($this->makeRequest([]), $this->response);

		$this->assertSame(422, $response->getStatusCode());
		$this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
		$this->assertSame(['ok' => false, 'error' => 'Missing or invalid tree'], $this->bodyAsJson($response));
	}

	public function testReturns500OnReorderFailedError(): void
	{
		// The "Reorder failed: ..." prefix is the contract that says "this came
		// from an internal write throw, not user input." Action maps it to 500.
		$this->service->method('applyTree')->willReturn([
			'ok'    => false,
			'error' => 'Reorder failed: disk full',
		]);

		$response = ($this->action)($this->makeRequest(['tree' => '[]']), $this->response);

		$this->assertSame(500, $response->getStatusCode());
		$this->assertSame(['ok' => false, 'error' => 'Reorder failed: disk full'], $this->bodyAsJson($response));
	}

	public function testForwardsParsedBodyToService(): void
	{
		$payload = ['tree' => '[{"id":"home","children":[]}]'];

		$this->service->expects($this->once())
			->method('applyTree')
			->with($payload)
			->willReturn(['ok' => true, 'count' => 1]);

		($this->action)($this->makeRequest($payload), $this->response);
	}

	public function testCountDefaultsToZeroWhenMissingFromSuccessTuple(): void
	{
		// Service contract should always include `count` on success, but be
		// defensive — the action shouldn't blow up if it's missing.
		$this->service->method('applyTree')->willReturn(['ok' => true]);

		$response = ($this->action)($this->makeRequest(['tree' => '[]']), $this->response);

		$this->assertSame(['ok' => true, 'count' => 0], $this->bodyAsJson($response));
	}

	public function testFallbackErrorMessageWhenErrorMissing(): void
	{
		// The service contract always sets `error` when `ok=false`; this is
		// defensive behavior for an impossible state. The fallback message is
		// "Reorder failed" which matches the 500 prefix, so we treat it as a
		// server-side failure rather than a 422 — "we don't know what went
		// wrong" is more honestly a server problem.
		$this->service->method('applyTree')->willReturn(['ok' => false]);

		$response = ($this->action)($this->makeRequest([]), $this->response);

		$this->assertSame(500, $response->getStatusCode());
		$this->assertSame(['ok' => false, 'error' => 'Reorder failed'], $this->bodyAsJson($response));
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function makeRequest(array $body): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn($body);

		return $request;
	}

	/** @return array<string,mixed> */
	private function bodyAsJson(ResponseInterface $response): array
	{
		$body = (string)$response->getBody();
		/** @var array<string,mixed> $decoded */
		$decoded = json_decode($body, true);

		return $decoded;
	}
}

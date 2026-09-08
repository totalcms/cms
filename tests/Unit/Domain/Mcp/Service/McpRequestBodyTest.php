<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Service\McpRequestBody;

final class McpRequestBodyTest extends TestCase
{
	/** @param array<string,mixed>|null $parsed */
	private function request(string $method, string $json, ?array $parsed = null): ServerRequest
	{
		$request = new ServerRequest($method, '/mcp', ['Content-Type' => 'application/json'], $json);
		if ($parsed !== null) {
			$request = $request->withParsedBody($parsed);
		}
		// Simulate a consumed stream (BodyParsingMiddleware / an earlier reader).
		$request->getBody()->getContents();

		return $request;
	}

	public function testReadsTheParsedBodyWhenSlimAlreadyParsedIt(): void
	{
		$request = $this->request('POST', '{}', ['method' => 'tools/list']);
		$body    = McpRequestBody::from($request);

		self::assertSame('tools/list', $body->method());
		self::assertSame('tools/list', $body->operation());
		self::assertSame(0, $request->getBody()->tell());
	}

	public function testFallsBackToTheRawStreamAndRewindsIt(): void
	{
		$request = $this->request('POST', '{"method":"tools/call","params":{"name":"get_object"}}');
		$body    = McpRequestBody::from($request);

		self::assertSame('tools/call', $body->method());
		self::assertSame('tools/call:get_object', $body->operation());
		self::assertSame(0, $request->getBody()->tell(), 'stream must be at 0 for the SDK transport');
		self::assertSame('{"method":"tools/call","params":{"name":"get_object"}}', $request->getBody()->getContents());
	}

	public function testMalformedOrEmptyBodiesYieldNoMethod(): void
	{
		self::assertSame('', McpRequestBody::from($this->request('POST', 'not json'))->method());
		self::assertSame('', McpRequestBody::from($this->request('GET', ''))->method());
		self::assertSame('', McpRequestBody::from($this->request('POST', '["a","b"]'))->operation());
	}

	public function testLifecycleMessagesAreExempt(): void
	{
		self::assertTrue(McpRequestBody::from($this->request('POST', '{"method":"ping"}'))->isLifecycle());
		self::assertTrue(McpRequestBody::from($this->request('POST', '{"method":"notifications/initialized"}'))->isLifecycle());
		self::assertFalse(McpRequestBody::from($this->request('POST', '{"method":"tools/list"}'))->isLifecycle());
	}

	public function testInitializeExposesClientInfoAndDefaultsToEmptyStrings(): void
	{
		$init = McpRequestBody::from($this->request('POST', '{"method":"initialize","params":{"clientInfo":{"name":"ChatGPT","version":"1.2"}}}'));
		self::assertTrue($init->isInitialize());
		self::assertSame(['name' => 'ChatGPT', 'version' => '1.2'], $init->clientInfo());

		$bare = McpRequestBody::from($this->request('POST', '{"method":"initialize"}'));
		self::assertSame(['name' => '', 'version' => ''], $bare->clientInfo());

		self::assertFalse(McpRequestBody::from($this->request('POST', '{"method":"tools/list"}'))->isInitialize());
	}

	public function testListenRequiresPostAndTheListenMethod(): void
	{
		self::assertTrue(McpRequestBody::from($this->request('POST', '{"method":"subscriptions/listen"}'))->isListen());
		self::assertFalse(McpRequestBody::from($this->request('GET', '{"method":"subscriptions/listen"}'))->isListen());
		self::assertFalse(McpRequestBody::from($this->request('POST', '{"method":"tools/list"}'))->isListen());
	}
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use Mcp\Event\ErrorEvent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use TotalCMS\Domain\Mcp\Service\McpSentryErrorDispatcher;

final class McpSentryErrorDispatcherTest extends TestCase
{
	private HubInterface $previousHub;

	protected function setUp(): void
	{
		$this->previousHub = SentrySdk::getCurrentHub();
	}

	protected function tearDown(): void
	{
		SentrySdk::setCurrentHub($this->previousHub);
	}

	/** Swap in a hub backed by a spy client and return that client. */
	private function spyClient(): ClientInterface
	{
		$client = $this->createMock(ClientInterface::class);
		SentrySdk::setCurrentHub(new Hub($client));

		return $client;
	}

	private function errorEvent(?\Throwable $throwable): ErrorEvent
	{
		$request = new class extends Request {
			public static function getMethod(): string
			{
				return 'tools/call';
			}

			protected static function fromParams(?array $params): static
			{
				return new static();
			}

			protected function getParams(): ?array
			{
				return null;
			}
		};

		return new ErrorEvent(
			Error::forInternalError('boom'),
			$request,
			$this->createMock(SessionInterface::class),
			$throwable,
		);
	}

	public function testCapturesRealHandlerException(): void
	{
		$throwable = new \RuntimeException('tool handler blew up');

		$client = $this->spyClient();
		$client->expects($this->once())
			->method('captureException')
			->with($throwable);

		$event      = $this->errorEvent($throwable);
		$dispatcher = new McpSentryErrorDispatcher();

		// PSR-14 contract: the event is returned (unchanged) for the SDK to use.
		$this->assertSame($event, $dispatcher->dispatch($event));
	}

	public function testIgnoresInvalidArgumentException(): void
	{
		// Invalid client params — the MCP equivalent of an HTTP 4xx, not a bug.
		$client = $this->spyClient();
		$client->expects($this->never())->method('captureException');

		$dispatcher = new McpSentryErrorDispatcher();
		$dispatcher->dispatch($this->errorEvent(new \InvalidArgumentException('bad params')));
	}

	public function testIgnoresErrorEventWithNoThrowable(): void
	{
		// SDK-generated protocol error (e.g. parse error), not a code bug.
		$client = $this->spyClient();
		$client->expects($this->never())->method('captureException');

		$dispatcher = new McpSentryErrorDispatcher();
		$dispatcher->dispatch($this->errorEvent(null));
	}

	public function testPassesNonErrorEventsThroughUntouched(): void
	{
		$client = $this->spyClient();
		$client->expects($this->never())->method('captureException');

		$other      = new \stdClass();
		$dispatcher = new McpSentryErrorDispatcher();

		$this->assertSame($other, $dispatcher->dispatch($other));
	}
}

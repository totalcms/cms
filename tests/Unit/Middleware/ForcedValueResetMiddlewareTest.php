<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\ForcedValueContext;
use TotalCMS\Middleware\ForcedValueResetMiddleware;

/**
 * A long-lived worker serves many requests from one process, so forced
 * values remembered in one request must be gone before the next starts.
 */
final class ForcedValueResetMiddlewareTest extends TestCase
{
	public function testClearsTheContextBeforeTheRequestIsHandled(): void
	{
		$context = new ForcedValueContext();
		$context->remember('showcase', 'site-1', ['draft' => true]);

		$request  = $this->createMock(ServerRequestInterface::class);
		$response = $this->createMock(ResponseInterface::class);

		$seenDuringHandle = null;
		$handler          = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->with($this->identicalTo($request))
			->willReturnCallback(function () use ($context, $response, &$seenDuringHandle): ResponseInterface {
				$seenDuringHandle = $context->forcedFor('showcase', 'site-1');

				return $response;
			});

		$result = (new ForcedValueResetMiddleware($context))->process($request, $handler);

		$this->assertSame($response, $result);
		$this->assertSame([], $seenDuringHandle);
		$this->assertSame([], $context->forcedFor('showcase', 'site-1'));
	}

	public function testLeavesValuesRememberedDuringTheRequestForItsOwnSaves(): void
	{
		// Clearing happens on the way in only: the request's own entries must
		// stay readable for every save it makes.
		$context = new ForcedValueContext();

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturnCallback(function () use ($context): ResponseInterface {
			$context->remember('showcase', 'site-1', ['draft' => true]);

			return $this->createMock(ResponseInterface::class);
		});

		(new ForcedValueResetMiddleware($context))->process($this->createMock(ServerRequestInterface::class), $handler);

		$this->assertSame(['draft' => true], $context->forcedFor('showcase', 'site-1'));
	}
}

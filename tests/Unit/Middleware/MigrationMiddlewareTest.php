<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Migration\Service\MigrationRunner;
use TotalCMS\Middleware\MigrationMiddleware;

/**
 * Reset the static "ran" flag so tests don't pollute each other regardless
 * of execution order.
 */
function resetMigrationMiddlewareFlag(): void
{
	$ref = new ReflectionClass(MigrationMiddleware::class);
	$ref->setStaticPropertyValue('ran', false);
}

describe('MigrationMiddleware', function (): void {
	beforeEach(function (): void {
		resetMigrationMiddlewareFlag();
	});

	test('runs the migration runner on the first request', function (): void {
		$runner = test()->createMock(MigrationRunner::class);
		$runner->expects(test()->once())->method('runPending');

		$response = test()->createMock(ResponseInterface::class);
		$handler  = test()->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($response);

		$middleware = new MigrationMiddleware($runner);
		$result     = $middleware->process(test()->createMock(ServerRequestInterface::class), $handler);

		expect($result)->toBe($response);
	});

	test('skips the runner on subsequent process() calls in the same process', function (): void {
		$runner = test()->createMock(MigrationRunner::class);
		$runner->expects(test()->once())->method('runPending');

		$handler = test()->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn(test()->createMock(ResponseInterface::class));

		$middleware = new MigrationMiddleware($runner);
		$middleware->process(test()->createMock(ServerRequestInterface::class), $handler);
		$middleware->process(test()->createMock(ServerRequestInterface::class), $handler);
		$middleware->process(test()->createMock(ServerRequestInterface::class), $handler);
	});

	test('always delegates to the next handler', function (): void {
		$runner = test()->createMock(MigrationRunner::class);

		$response = test()->createMock(ResponseInterface::class);
		$handler  = test()->createMock(RequestHandlerInterface::class);
		$handler->expects(test()->exactly(2))->method('handle')->willReturn($response);

		$middleware = new MigrationMiddleware($runner);
		$middleware->process(test()->createMock(ServerRequestInterface::class), $handler);
		$middleware->process(test()->createMock(ServerRequestInterface::class), $handler);
	});
});

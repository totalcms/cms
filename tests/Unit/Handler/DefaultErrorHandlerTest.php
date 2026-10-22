<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Handler\DefaultErrorHandler;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\RawRenderer;

/**
 * The OPcache reset in the default error handler exists so a fixed file
 * takes effect right after a fault. An HTTP exception is not a fault in the
 * code — a routing 404 from a bot probing /wp-login.php must not flush the
 * compiled bytecode for the whole site on every hit.
 */
beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$this->opcache = $this->createMock(OPcacheService::class);
	$this->opcache->method('isAvailable')->willReturn(true);

	$container     = $this->app->getContainer();
	$this->handler = new DefaultErrorHandler(
		$container->get(JsonRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(LoggerFactory::class),
		$this->opcache,
		$container->get(RawRenderer::class),
	);
	$this->request = (new ServerRequestFactory())->createServerRequest('GET', '/wp-login.php');
});

test('a routing 404 leaves OPcache alone and does not claim to have cleared it', function (): void {
	$this->opcache->expects($this->never())->method('clear');

	$response = ($this->handler)($this->request, new HttpNotFoundException($this->request), false, false);

	expect($response->getStatusCode())->toBe(404)
		->and($response->hasHeader('X-OPcache-Cleared'))->toBeFalse();
});

test('a real fault still resets OPcache and says so', function (): void {
	$this->opcache->expects($this->once())->method('clear')->willReturn(true);

	$response = ($this->handler)($this->request, new RuntimeException('a fault in the code'), false, false);

	expect($response->getStatusCode())->toBe(500)
		->and($response->getHeaderLine('X-OPcache-Cleared'))->toBe('true');
});

test('a write the storage layer refused is a 403, not a fault', function (): void {
	$this->opcache->expects($this->never())->method('clear');

	$response = ($this->handler)($this->request, new AccessDeniedException('You may not update object "a" in "b".'), false, false);

	expect($response->getStatusCode())->toBe(403)
		->and($response->hasHeader('X-OPcache-Cleared'))->toBeFalse();
});

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRunner;
use TotalCMS\Factory\LoggerFactory;

final class PageMiddlewareRunnerTest extends TestCase
{
	private PageMiddlewareRegistry&MockObject $registry;
	private PageMiddlewareRunner $runner;
	private ServerRequestInterface $request;

	protected function setUp(): void
	{
		$this->registry = $this->createMock(PageMiddlewareRegistry::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->runner  = new PageMiddlewareRunner($this->registry, $loggerFactory);
		$this->request = (new Psr17Factory())->createServerRequest('GET', '/about');
	}

	public function testReturnsNullWhenPageHasNoMiddleware(): void
	{
		$page = $this->page([]);

		$this->registry->expects($this->never())->method('resolve');

		$this->assertNull($this->runner->run($this->request, $page));
	}

	public function testProceedsWhenAllMiddlewareReturnNull(): void
	{
		$mw1 = $this->mwReturning(null);
		$mw2 = $this->mwReturning(null);

		$this->registry->method('resolve')->willReturnMap([
			['auth', $mw1],
			['log', $mw2],
		]);

		$this->assertNull($this->runner->run($this->request, $this->page(['auth', 'log'])));
	}

	public function testFirstShortCircuitWins(): void
	{
		$shortResponse = (new Psr17Factory())->createResponse(401);
		$mw1           = $this->mwReturning($shortResponse);
		$mw2           = $this->mwReturning((new Psr17Factory())->createResponse(403));

		$this->registry->method('resolve')->willReturnMap([
			['auth', $mw1],
			['rate-limit', $mw2],
		]);
		// Second middleware should not be called.
		$mw2->expects($this->never())->method('handle');

		$this->assertSame($shortResponse, $this->runner->run($this->request, $this->page(['auth', 'rate-limit'])));
	}

	public function testRunsMiddlewareInDeclaredOrder(): void
	{
		$callOrder = [];
		$mw1       = $this->mwSpy(function () use (&$callOrder): ?ResponseInterface {
			$callOrder[] = 'auth';

			return null;
		});
		$mw2 = $this->mwSpy(function () use (&$callOrder): ?ResponseInterface {
			$callOrder[] = 'rate-limit';

			return null;
		});

		$this->registry->method('resolve')->willReturnMap([
			['auth', $mw1],
			['rate-limit', $mw2],
		]);

		$this->runner->run($this->request, $this->page(['auth', 'rate-limit']));

		$this->assertSame(['auth', 'rate-limit'], $callOrder);
	}

	public function testUnknownMiddlewareNameIsSkippedNotFatal(): void
	{
		$mw = $this->mwReturning(null);

		$this->registry->method('resolve')->willReturnCallback(
			fn (string $name): ?PageMiddlewareInterface => $name === 'auth' ? $mw : null,
		);

		// Mix of unknown + known. Unknown is silently skipped, known still runs.
		$this->assertNull($this->runner->run($this->request, $this->page(['nope', 'auth', 'also-nope'])));
	}

	public function testThrowingMiddlewareFailsClosedWith500(): void
	{
		$mw = $this->createMock(PageMiddlewareInterface::class);
		$mw->method('handle')->willThrowException(new \RuntimeException('boom'));

		$this->registry->method('resolve')->willReturn($mw);

		$response = $this->runner->run($this->request, $this->page(['auth']));

		$this->assertNotNull($response);
		$this->assertSame(500, $response->getStatusCode());
		$this->assertStringContainsString('boom', (string)$response->getBody());
	}

	public function testThrowingMiddlewareDoesNotRunSubsequent(): void
	{
		$mw1 = $this->createMock(PageMiddlewareInterface::class);
		$mw1->method('handle')->willThrowException(new \RuntimeException('boom'));
		$mw2 = $this->createMock(PageMiddlewareInterface::class);
		$mw2->expects($this->never())->method('handle');

		$this->registry->method('resolve')->willReturnMap([
			['auth', $mw1],
			['rate-limit', $mw2],
		]);

		$this->runner->run($this->request, $this->page(['auth', 'rate-limit']));
	}

	/** @param list<string> $middleware */
	private function page(array $middleware): PageData
	{
		return new PageData(['id' => 'about', 'middleware' => $middleware]);
	}

	private function mwReturning(?ResponseInterface $result): PageMiddlewareInterface&MockObject
	{
		$mw = $this->createMock(PageMiddlewareInterface::class);
		$mw->method('handle')->willReturn($result);

		return $mw;
	}

	private function mwSpy(callable $fn): PageMiddlewareInterface&MockObject
	{
		$mw = $this->createMock(PageMiddlewareInterface::class);
		$mw->method('handle')->willReturnCallback($fn);

		return $mw;
	}
}

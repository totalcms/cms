<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Middleware\SetupLocaleMiddleware;

final class SetupLocaleMiddlewareTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $translationService;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private SetupLocaleMiddleware $middleware;

	protected function setUp(): void
	{
		$this->session            = $this->createMock(SessionInterface::class);
		$this->translationService = $this->createMock(TranslationService::class);
		$this->handler            = $this->createMock(RequestHandlerInterface::class);

		$this->middleware = new SetupLocaleMiddleware($this->session, $this->translationService);
	}

	public function testPassesThroughWithoutLocale(): void
	{
		$this->session->method('get')->with('setup_locale')->willReturn(null);
		$this->translationService->expects($this->never())->method('setLocale');

		$request  = $this->createMock(ServerRequestInterface::class);
		$expected = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($expected);

		$result = $this->middleware->process($request, $this->handler);
		$this->assertSame($expected, $result);
	}

	public function testSetsLocaleWhenNonEnglish(): void
	{
		$this->session->method('get')->with('setup_locale')->willReturn('de_DE');
		$this->translationService->expects($this->once())->method('setLocale')->with('de_DE');

		$request  = $this->createMock(ServerRequestInterface::class);
		$expected = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($expected);

		$result = $this->middleware->process($request, $this->handler);
		$this->assertSame($expected, $result);
	}

	public function testSkipsLocaleSetForEnUS(): void
	{
		$this->session->method('get')->with('setup_locale')->willReturn('en_US');
		$this->translationService->expects($this->never())->method('setLocale');

		$request  = $this->createMock(ServerRequestInterface::class);
		$expected = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($expected);

		$result = $this->middleware->process($request, $this->handler);
		$this->assertSame($expected, $result);
	}

	public function testSkipsEmptyLocale(): void
	{
		$this->session->method('get')->with('setup_locale')->willReturn('');
		$this->translationService->expects($this->never())->method('setLocale');

		$request  = $this->createMock(ServerRequestInterface::class);
		$expected = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($expected);

		$result = $this->middleware->process($request, $this->handler);
		$this->assertSame($expected, $result);
	}
}

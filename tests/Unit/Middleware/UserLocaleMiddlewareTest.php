<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Middleware\UserLocaleMiddleware;

final class UserLocaleMiddlewareTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $accessManager;
	private \PHPUnit\Framework\MockObject\MockObject $translationService;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private UserLocaleMiddleware $middleware;

	protected function setUp(): void
	{
		$this->accessManager      = $this->createMock(AccessManager::class);
		$this->translationService = $this->createMock(TranslationService::class);
		$this->handler            = $this->createMock(RequestHandlerInterface::class);

		$this->middleware = new UserLocaleMiddleware($this->accessManager, $this->translationService);
	}

	/** @param array<string,mixed> $userData */
	private function runMiddleware(array $userData): ResponseInterface
	{
		$this->accessManager->method('userData')->willReturn($userData);

		$request  = $this->createMock(ServerRequestInterface::class);
		$expected = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($expected);

		$result = $this->middleware->process($request, $this->handler);
		$this->assertSame($expected, $result);

		return $result;
	}

	public function testSetsLocaleWhenUserHasPreference(): void
	{
		$this->translationService->expects($this->once())->method('setLocale')->with('de_DE');
		$this->runMiddleware(['locale' => 'de_DE']);
	}

	public function testPassesThroughWhenNoLocaleField(): void
	{
		$this->translationService->expects($this->never())->method('setLocale');
		$this->runMiddleware(['name' => 'Joe']);
	}

	public function testSkipsEmptyLocale(): void
	{
		$this->translationService->expects($this->never())->method('setLocale');
		$this->runMiddleware(['locale' => '']);
	}

	public function testSkipsWhenNotLoggedIn(): void
	{
		$this->translationService->expects($this->never())->method('setLocale');
		$this->runMiddleware([]);
	}
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Setup;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Setup\WelcomeAction;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\TwigRenderer;

final class WelcomeActionTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $twigRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $translationService;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private WelcomeAction $action;

	protected function setUp(): void
	{
		$this->twigRenderer       = $this->createMock(TwigRenderer::class);
		$this->translationService = $this->createMock(TranslationService::class);
		$this->session            = $this->createMock(SessionInterface::class);

		$this->action = new WelcomeAction(
			$this->twigRenderer,
			$this->translationService,
			$this->session,
		);
	}

	public function testRendersWelcomeTemplate(): void
	{
		$this->session->method('get')->willReturn('en_US');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$this->anything(),
				'setup/welcome.twig',
				$this->callback(function (array $data): bool {
					expect($data['url']['page'])->toBe('setup');
					expect($data['locales'])->toBeArray();
					expect($data['locales'])->toHaveKey('en_US');
					expect($data['locales'])->toHaveKey('de_DE');

					return true;
				})
			)
			->willReturn($expected);

		$result = ($this->action)($this->createRequest(), $this->createMock(ResponseInterface::class));
		$this->assertSame($expected, $result);
	}

	public function testSetsLocaleFromQueryParam(): void
	{
		$this->session->expects($this->once())
			->method('set')
			->with('setup_locale', 'de_DE');

		$this->translationService->expects($this->once())
			->method('setLocale')
			->with('de_DE');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->method('template')->willReturn($expected);

		$request = $this->createRequest(['lang' => 'de_DE']);
		($this->action)($request, $this->createMock(ResponseInterface::class));
	}

	public function testIgnoresInvalidLocale(): void
	{
		// Invalid locale should not be set
		$this->session->expects($this->never())->method('set');
		$this->translationService->expects($this->never())->method('setLocale');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->method('template')->willReturn($expected);

		$request = $this->createRequest(['lang' => 'xx_XX']);
		($this->action)($request, $this->createMock(ResponseInterface::class));
	}

	/**
	 * @param array<string,string> $queryParams
	 */
	private function createRequest(array $queryParams = []): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/setup');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn($queryParams);
		$request->method('getUri')->willReturn($uri);

		return $request;
	}
}

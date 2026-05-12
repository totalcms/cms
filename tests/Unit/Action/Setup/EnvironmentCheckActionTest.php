<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Setup;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Setup\EnvironmentCheckAction;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Renderer\TwigRenderer;

final class EnvironmentCheckActionTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $twigRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $serverChecker;
	private \PHPUnit\Framework\MockObject\MockObject $setupState;
	private EnvironmentCheckAction $action;

	protected function setUp(): void
	{
		$this->twigRenderer  = $this->createMock(TwigRenderer::class);
		$this->serverChecker = $this->createMock(ServerChecker::class);
		$this->setupState    = $this->createMock(SetupStateManager::class);

		$this->action = new EnvironmentCheckAction(
			$this->twigRenderer,
			$this->serverChecker,
			$this->setupState,
		);
	}

	public function testCompletesStepWhenAllRequiredPass(): void
	{
		$this->serverChecker->method('checkRequiredSoftware')->willReturn([
			'PHP >= 8.2' => true,
			'JSON'       => true,
		]);
		$this->serverChecker->method('checkOptionalSoftware')->willReturn(['APCu' => false]);
		$this->serverChecker->method('getOptionalSoftwareDetails')->willReturn([]);

		$this->setupState->expects($this->once())
			->method('completeStep')
			->with('environment');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$this->anything(),
				'setup/environment.twig',
				$this->callback(function (array $data): bool {
					expect($data['allRequiredPassed'])->toBeTrue();
					expect($data['required'])->toHaveCount(2);

					return true;
				})
			)
			->willReturn($expected);

		$result = ($this->action)($this->createRequest(), $this->createMock(ResponseInterface::class));
		$this->assertSame($expected, $result);
	}

	public function testDoesNotCompleteStepWhenRequiredFails(): void
	{
		$this->serverChecker->method('checkRequiredSoftware')->willReturn([
			'PHP >= 8.2' => true,
			'imagick'    => false,
		]);
		$this->serverChecker->method('checkOptionalSoftware')->willReturn([]);
		$this->serverChecker->method('getOptionalSoftwareDetails')->willReturn([]);

		$this->setupState->expects($this->never())->method('completeStep');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$this->anything(),
				'setup/environment.twig',
				$this->callback(function (array $data): bool {
					expect($data['allRequiredPassed'])->toBeFalse();

					return true;
				})
			)
			->willReturn($expected);

		($this->action)($this->createRequest(), $this->createMock(ResponseInterface::class));
	}

	private function createRequest(): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/setup/environment');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);

		return $request;
	}
}

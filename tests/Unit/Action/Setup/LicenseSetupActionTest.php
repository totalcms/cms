<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Setup;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Setup\LicenseSetupAction;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Renderer\TwigRenderer;

final class LicenseSetupActionTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $twigRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $licenseValidator;
	private \PHPUnit\Framework\MockObject\MockObject $setupState;
	private LicenseSetupAction $action;

	protected function setUp(): void
	{
		$this->twigRenderer     = $this->createMock(TwigRenderer::class);
		$this->licenseValidator = $this->createMock(LicenseValidator::class);
		$this->setupState       = $this->createMock(SetupStateManager::class);

		$this->action = new LicenseSetupAction(
			$this->twigRenderer,
			$this->licenseValidator,
			$this->setupState,
		);
	}

	public function testCompletesStepAndRendersLicense(): void
	{
		$license = new LicenseData(
			valid: true,
			trial: true,
			domain: 'example.com',
			edition: 'trial',
			message: 'Trial active',
			validationToken: null,
			updatesValid: true,
		);

		$this->licenseValidator->method('validateLicense')->willReturn($license);

		$this->setupState->expects($this->once())
			->method('completeStep')
			->with('license');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$this->anything(),
				'setup/license.twig',
				$this->callback(function (array $data) use ($license): bool {
					expect($data['license'])->toBe($license);
					expect($data['error'])->toBeNull();

					return true;
				})
			)
			->willReturn($expected);

		$result = ($this->action)($this->createRequest(), $this->createMock(ResponseInterface::class));
		$this->assertSame($expected, $result);
	}

	public function testCompletesStepEvenOnLicenseError(): void
	{
		$this->licenseValidator->method('validateLicense')
			->willThrowException(new \RuntimeException('Connection refused'));

		$this->setupState->expects($this->once())
			->method('completeStep')
			->with('license');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$this->anything(),
				'setup/license.twig',
				$this->callback(function (array $data): bool {
					expect($data['license'])->toBeNull();
					expect($data['error'])->toContain('Connection refused');

					return true;
				})
			)
			->willReturn($expected);

		($this->action)($this->createRequest(), $this->createMock(ResponseInterface::class));
	}

	private function createRequest(): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/setup/license');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);

		return $request;
	}
}

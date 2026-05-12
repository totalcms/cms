<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Setup;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Setup\SetupCompleteAction;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

final class SetupCompleteActionTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $twigRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $licenseValidator;
	private SetupCompleteAction $action;

	protected function setUp(): void
	{
		$this->twigRenderer     = $this->createMock(TwigRenderer::class);
		$this->config           = $this->createMock(Config::class);
		$this->session          = $this->createMock(SessionInterface::class);
		$this->licenseValidator = $this->createMock(LicenseValidator::class);

		$this->config->datadir = '/var/www/tcms-data';

		$this->action = new SetupCompleteAction(
			$this->twigRenderer,
			$this->config,
			$this->session,
			$this->licenseValidator,
		);
	}

	public function testRendersCompletePageWithLicense(): void
	{
		$license = new LicenseData(
			valid: true,
			trial: false,
			domain: 'example.com',
			edition: 'pro',
			message: '',
			validationToken: null,
			updatesValid: true,
		);
		$this->licenseValidator->method('validateLicense')->willReturn($license);
		$this->session->method('get')->with('setup_admin_email', '')->willReturn('admin@example.com');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$this->anything(),
				'setup/complete.twig',
				$this->callback(function (array $data): bool {
					expect($data['edition'])->toBe('pro');
					expect($data['datadir'])->toBe('/var/www/tcms-data');
					expect($data['adminEmail'])->toBe('admin@example.com');
					expect($data['url']['page'])->toBe('setup');

					return true;
				})
			)
			->willReturn($expected);

		$result = ($this->action)($this->createRequest(), $this->createMock(ResponseInterface::class));
		$this->assertSame($expected, $result);
	}

	public function testHandlesLicenseValidationFailure(): void
	{
		$this->licenseValidator->method('validateLicense')
			->willThrowException(new \RuntimeException('License error'));
		$this->session->method('get')->willReturn('');

		$expected = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$this->anything(),
				'setup/complete.twig',
				$this->callback(function (array $data): bool {
					expect($data['edition'])->toBe('unknown');

					return true;
				})
			)
			->willReturn($expected);

		($this->action)($this->createRequest(), $this->createMock(ResponseInterface::class));
	}

	private function createRequest(): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/setup/complete');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);

		return $request;
	}
}

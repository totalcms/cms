<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Setup;

use Odan\Session\FlashInterface;
use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Setup\LicenseVerifySubmitAction;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\RedirectRenderer;

final class LicenseVerifySubmitActionTest extends TestCase
{
	private LicenseVerifySubmitAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $licenseValidator;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $redirectRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $translator;
	private \PHPUnit\Framework\MockObject\MockObject $flash;

	protected function setUp(): void
	{
		$this->licenseValidator = $this->createMock(LicenseValidator::class);
		$this->session          = $this->createMock(SessionInterface::class);
		$this->redirectRenderer = $this->createMock(RedirectRenderer::class);
		$this->translator       = $this->createMock(TranslationService::class);
		$this->flash            = $this->createMock(FlashInterface::class);

		$this->session->method('getFlash')->willReturn($this->flash);
		$this->translator->method('trans')->willReturnArgument(0);

		$redirectResponse = $this->createMock(ResponseInterface::class);
		$this->redirectRenderer->method('redirectFor')->willReturn($redirectResponse);

		$this->action = new LicenseVerifySubmitAction(
			$this->licenseValidator,
			$this->session,
			$this->redirectRenderer,
			$this->translator,
		);
	}

	public function testRedirectsSilentlyWhenNoPending(): void
	{
		$this->session->method('get')->with('setup_license_pending')->willReturn(null);

		$this->licenseValidator->expects($this->never())->method('verifyTrial');
		$this->flash->expects($this->never())->method('add');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-license');

		($this->action)($this->createRequest(['code' => '123456']), $this->createMock(ResponseInterface::class));
	}

	public function testFlashesOnBadCodeFormatWithoutCallingValidator(): void
	{
		$this->session->method('get')
			->with('setup_license_pending')
			->willReturn(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

		$this->licenseValidator->expects($this->never())->method('verifyTrial');
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		($this->action)($this->createRequest(['code' => 'abc']), $this->createMock(ResponseInterface::class));
	}

	public function testFlashesOnBadCodeLengthWithoutCallingValidator(): void
	{
		$this->session->method('get')
			->with('setup_license_pending')
			->willReturn(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

		$this->licenseValidator->expects($this->never())->method('verifyTrial');
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		($this->action)($this->createRequest(['code' => '12345']), $this->createMock(ResponseInterface::class));
	}

	public function testVerifiesCodeAndClearsPending(): void
	{
		$this->session->method('get')
			->with('setup_license_pending')
			->willReturn(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

		$this->licenseValidator->expects($this->once())
			->method('verifyTrial')
			->with('jane@example.com', '123456');

		$this->session->expects($this->once())->method('delete')->with('setup_license_pending');
		$this->flash->expects($this->never())->method('add');

		($this->action)($this->createRequest(['code' => '123456']), $this->createMock(ResponseInterface::class));
	}

	public function testLicenseExceptionFlashesServerMessageAndKeepsPending(): void
	{
		$this->session->method('get')
			->with('setup_license_pending')
			->willReturn(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

		$this->licenseValidator->method('verifyTrial')
			->willThrowException(new LicenseException('Invalid or expired code'));

		$this->session->expects($this->never())->method('delete');
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-license');

		($this->action)($this->createRequest(['code' => '123456']), $this->createMock(ResponseInterface::class));
	}

	/**
	 * @param array<string,string> $body
	 */
	private function createRequest(array $body): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn($body);

		return $request;
	}
}

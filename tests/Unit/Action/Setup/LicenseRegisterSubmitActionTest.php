<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Setup;

use Odan\Session\FlashInterface;
use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Setup\LicenseRegisterSubmitAction;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\RedirectRenderer;

final class LicenseRegisterSubmitActionTest extends TestCase
{
	private LicenseRegisterSubmitAction $action;
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

		$this->action = new LicenseRegisterSubmitAction(
			$this->licenseValidator,
			$this->session,
			$this->redirectRenderer,
			$this->translator,
		);
	}

	public function testRedirectsOnEmptyName(): void
	{
		$this->session->method('get')->with('setup_license_pending')->willReturn(null);

		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());
		$this->licenseValidator->expects($this->never())->method('registerTrial');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-license');

		($this->action)($this->createRequest(['name' => '', 'email' => 'jane@example.com']), $this->createMock(ResponseInterface::class));
	}

	public function testRedirectsOnInvalidEmail(): void
	{
		$this->session->method('get')->with('setup_license_pending')->willReturn(null);

		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());
		$this->licenseValidator->expects($this->never())->method('registerTrial');

		($this->action)($this->createRequest(['name' => 'Jane Smith', 'email' => 'not-an-email']), $this->createMock(ResponseInterface::class));
	}

	public function testVerificationRequiredStashesPending(): void
	{
		$this->session->method('get')->with('setup_license_pending')->willReturn(null);

		$this->licenseValidator->expects($this->once())
			->method('registerTrial')
			->with('Jane Smith', 'jane@example.com')
			->willReturn(['status' => 'verification_required', 'email' => 'jane@example.com']);

		$this->session->expects($this->once())
			->method('set')
			->with('setup_license_pending', ['name' => 'Jane Smith', 'email' => 'jane@example.com']);

		$this->flash->expects($this->never())->method('add');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-license');

		($this->action)($this->createRequest(['name' => 'Jane Smith', 'email' => 'jane@example.com']), $this->createMock(ResponseInterface::class));
	}

	public function testTrialCreatedClearsPending(): void
	{
		$this->session->method('get')->with('setup_license_pending')->willReturn(null);

		$this->licenseValidator->method('registerTrial')
			->willReturn(['status' => 'trial_created']);

		$this->session->expects($this->once())->method('delete')->with('setup_license_pending');
		$this->session->expects($this->never())->method('set');

		($this->action)($this->createRequest(['name' => 'Jane Smith', 'email' => 'jane@example.com']), $this->createMock(ResponseInterface::class));
	}

	public function testLicenseExceptionFlashesServerMessage(): void
	{
		$this->session->method('get')->with('setup_license_pending')->willReturn(null);

		$this->licenseValidator->method('registerTrial')
			->willThrowException(new LicenseException('Trial already registered to a different contact'));

		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-license');

		($this->action)($this->createRequest(['name' => 'Jane Smith', 'email' => 'jane@example.com']), $this->createMock(ResponseInterface::class));
	}

	public function testEmptyResendPostFallsBackToPendingSessionValues(): void
	{
		// The code screen's "Resend code" link is just an empty re-POST to this
		// same route — the name/email should come from the pending session data.
		$this->session->method('get')
			->with('setup_license_pending')
			->willReturn(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

		$this->licenseValidator->expects($this->once())
			->method('registerTrial')
			->with('Jane Smith', 'jane@example.com')
			->willReturn(['status' => 'verification_required', 'email' => 'jane@example.com']);

		($this->action)($this->createRequest([]), $this->createMock(ResponseInterface::class));
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Setup;

use Odan\Session\FlashInterface;
use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Setup\AccountSetupSubmitAction;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\RedirectRenderer;

final class AccountSetupSubmitActionTest extends TestCase
{
	private AccountSetupSubmitAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $firstLoginChecker;
	private \PHPUnit\Framework\MockObject\MockObject $setupState;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $redirectRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $translator;
	private \PHPUnit\Framework\MockObject\MockObject $flash;

	protected function setUp(): void
	{
		$this->firstLoginChecker = $this->createMock(FirstLoginChecker::class);
		$this->setupState        = $this->createMock(SetupStateManager::class);
		$this->session           = $this->createMock(SessionInterface::class);
		$this->redirectRenderer  = $this->createMock(RedirectRenderer::class);
		$this->translator        = $this->createMock(TranslationService::class);
		$this->flash             = $this->createMock(FlashInterface::class);

		$this->session->method('getFlash')->willReturn($this->flash);
		$this->translator->method('trans')->willReturnArgument(0);

		$redirectResponse = $this->createMock(ResponseInterface::class);
		$this->redirectRenderer->method('redirectFor')->willReturn($redirectResponse);

		$this->action = new AccountSetupSubmitAction(
			$this->firstLoginChecker,
			$this->setupState,
			$this->session,
			$this->redirectRenderer,
			$this->translator,
		);
	}

	public function testRedirectsOnEmptyEmail(): void
	{
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());
		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-account');

		$this->firstLoginChecker->expects($this->never())->method('createFirstUser');

		($this->action)($this->createRequest(['email' => '', 'password' => 'test1234', 'password-confirm' => 'test1234']), $this->createMock(ResponseInterface::class));
	}

	public function testRedirectsOnInvalidEmail(): void
	{
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		$this->firstLoginChecker->expects($this->never())->method('createFirstUser');

		($this->action)($this->createRequest(['email' => 'not-an-email', 'password' => 'test1234', 'password-confirm' => 'test1234']), $this->createMock(ResponseInterface::class));
	}

	public function testRedirectsOnEmptyPassword(): void
	{
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		$this->firstLoginChecker->expects($this->never())->method('createFirstUser');

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => '', 'password-confirm' => '']), $this->createMock(ResponseInterface::class));
	}

	public function testRedirectsOnShortPassword(): void
	{
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		$this->firstLoginChecker->expects($this->never())->method('createFirstUser');

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => 'short', 'password-confirm' => 'short']), $this->createMock(ResponseInterface::class));
	}

	public function testRedirectsOnPasswordMismatch(): void
	{
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		$this->firstLoginChecker->expects($this->never())->method('createFirstUser');

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => 'password123', 'password-confirm' => 'different']), $this->createMock(ResponseInterface::class));
	}

	public function testCreatesUserAndRedirectsToLicense(): void
	{
		$this->firstLoginChecker->expects($this->once())
			->method('createFirstUser')
			->with('admin@example.com', 'password123');

		$this->setupState->expects($this->once())
			->method('completeStep')
			->with('account');

		$this->session->expects($this->once())
			->method('set')
			->with('setup_admin_email', 'admin@example.com');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-license');

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => 'password123', 'password-confirm' => 'password123']), $this->createMock(ResponseInterface::class));
	}

	public function testHandlesUserCreationFailure(): void
	{
		$this->firstLoginChecker->method('createFirstUser')
			->willThrowException(new \RuntimeException('Auth collection missing'));

		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());
		$this->setupState->expects($this->never())->method('completeStep');

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => 'password123', 'password-confirm' => 'password123']), $this->createMock(ResponseInterface::class));
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

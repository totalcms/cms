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
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Support\Config;

final class AccountSetupSubmitActionTest extends TestCase
{
	private AccountSetupSubmitAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $firstLoginChecker;
	private \PHPUnit\Framework\MockObject\MockObject $loginService;
	private \PHPUnit\Framework\MockObject\MockObject $sessionLogin;
	private \PHPUnit\Framework\MockObject\MockObject $setupState;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $redirectRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $translator;
	private \PHPUnit\Framework\MockObject\MockObject $flash;
	private Config $config;

	protected function setUp(): void
	{
		$this->firstLoginChecker = $this->createMock(FirstLoginChecker::class);
		$this->loginService      = $this->createMock(LoginService::class);
		$this->sessionLogin      = $this->createMock(SessionLogin::class);
		$this->setupState        = $this->createMock(SetupStateManager::class);
		$this->session           = $this->createMock(SessionInterface::class);
		$this->redirectRenderer  = $this->createMock(RedirectRenderer::class);
		$this->translator        = $this->createMock(TranslationService::class);
		$this->flash             = $this->createMock(FlashInterface::class);

		// Config is a plain data class; instantiate without ctor and set the
		// `auth.collection` value the action reads when establishing a session.
		$this->config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->auth = ['collection' => 'admin'];

		$this->session->method('getFlash')->willReturn($this->flash);
		$this->translator->method('trans')->willReturnArgument(0);

		$redirectResponse = $this->createMock(ResponseInterface::class);
		$this->redirectRenderer->method('redirectFor')->willReturn($redirectResponse);

		$this->action = new AccountSetupSubmitAction(
			$this->firstLoginChecker,
			$this->loginService,
			$this->sessionLogin,
			$this->setupState,
			$this->session,
			$this->redirectRenderer,
			$this->translator,
			$this->config,
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

	public function testEmailIsStashedEvenWhenValidationFails(): void
	{
		// Validation fails (short password) but the form should still remember
		// the email so the operator doesn't have to re-type it on the redirect.
		$this->firstLoginChecker->expects($this->never())->method('createFirstUser');

		$captured = [];
		$this->session->method('set')
			->willReturnCallback(static function (string $key, mixed $value) use (&$captured): void {
				$captured[$key] = $value;
			});

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => 'short', 'password-confirm' => 'short']), $this->createMock(ResponseInterface::class));

		$this->assertSame('admin@example.com', $captured['setup_admin_email'] ?? null);
	}

	public function testRedirectsOnPasswordMismatch(): void
	{
		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());

		$this->firstLoginChecker->expects($this->never())->method('createFirstUser');

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => 'password123', 'password-confirm' => 'different']), $this->createMock(ResponseInterface::class));
	}

	public function testCreatesUserAutoLoginsAndRedirectsToLicense(): void
	{
		$this->firstLoginChecker->expects($this->once())
			->method('createFirstUser')
			->with('admin@example.com', 'password123');

		$this->setupState->expects($this->once())
			->method('completeStep')
			->with('account');

		// authenticate is called with the just-typed credentials and returns
		// the freshly-created user record.
		$this->loginService->expects($this->once())
			->method('authenticate')
			->with('admin@example.com', 'password123')
			->willReturn(['id' => 'admin']);

		// SessionLogin handles the actual session-key writes (covered by its own
		// tests); here we just verify the action delegates with the right args.
		$this->sessionLogin->expects($this->once())
			->method('establish')
			->with('admin', 'admin');

		// Only the email is stashed directly on the session by this action — so
		// the form can repopulate it on validation failure and the complete
		// page can display "logged in as".
		$captured = [];
		$this->session->method('set')
			->willReturnCallback(static function (string $key, mixed $value) use (&$captured): void {
				$captured[$key] = $value;
			});

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-license');

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => 'password123', 'password-confirm' => 'password123']), $this->createMock(ResponseInterface::class));

		$this->assertSame(['setup_admin_email' => 'admin@example.com'], $captured);
	}

	public function testHandlesUserCreationFailure(): void
	{
		$this->firstLoginChecker->method('createFirstUser')
			->willThrowException(new \RuntimeException('Auth collection missing'));

		$this->flash->expects($this->once())->method('add')->with('error', $this->anything());
		$this->setupState->expects($this->never())->method('completeStep');
		$this->loginService->expects($this->never())->method('authenticate');

		($this->action)($this->createRequest(['email' => 'admin@example.com', 'password' => 'password123', 'password-confirm' => 'password123']), $this->createMock(ResponseInterface::class));
	}

	public function testAutoLoginFailureIsNonFatal(): void
	{
		// User creation succeeds, but the follow-up auto-login throws. The
		// account still exists on disk and the wizard should continue to the
		// license step — the operator can sign in manually if needed.
		$this->firstLoginChecker->expects($this->once())->method('createFirstUser');
		$this->setupState->expects($this->once())->method('completeStep')->with('account');

		$this->loginService->method('authenticate')
			->willThrowException(new \RuntimeException('Auth backend unreachable'));

		// Auto-login failure means we never get as far as establishing a session.
		$this->sessionLogin->expects($this->never())->method('establish');

		// No error flash for auto-login failures — it's silent on purpose.
		$this->flash->expects($this->never())->method('add');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-license');

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

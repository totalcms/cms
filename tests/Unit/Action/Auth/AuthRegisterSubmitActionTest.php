<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Auth;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Action\Auth\AuthRegisterSubmitAction;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class AuthRegisterSubmitActionTest extends TestCase
{
	private AuthRegisterSubmitAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $objectSaver;
	private \PHPUnit\Framework\MockObject\MockObject $loginService;
	private \PHPUnit\Framework\MockObject\MockObject $sessionLogin;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $verificationService;
	private \PHPUnit\Framework\MockObject\MockObject $emailService;
	private \PHPUnit\Framework\MockObject\MockObject $emailSender;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;
	private Config $config;

	protected function setUp(): void
	{
		$this->renderer            = $this->createMock(JsonRenderer::class);
		$this->objectSaver         = $this->createMock(ObjectSaver::class);
		$this->loginService        = $this->createMock(LoginService::class);
		$this->sessionLogin        = $this->createMock(SessionLogin::class);
		$this->collectionFetcher   = $this->createMock(CollectionFetcher::class);
		$this->verificationService = $this->createMock(EmailVerificationService::class);
		$this->emailService        = $this->createMock(EmailService::class);
		$this->emailSender         = $this->createMock(EmailSender::class);
		$this->twigEngine          = $this->createMock(TwigEngine::class);

		$this->config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->auth = [
			'collection'              => 'admin',
			'publicRegistration'      => ['members'],
			'verificationTokenExpiry' => 1440,
			'verificationMailerId'    => '',
		];
		$this->config->url = 'https://example.test';
		$this->config->api = '/api';

		$this->action = new AuthRegisterSubmitAction(
			$this->renderer,
			$this->objectSaver,
			$this->loginService,
			$this->sessionLogin,
			$this->collectionFetcher,
			$this->verificationService,
			$this->emailService,
			$this->emailSender,
			$this->twigEngine,
			$this->config,
		);
	}

	public function testRejectsCollectionNotInAllowList(): void
	{
		// 'admin' is the default auth collection but is NOT in publicRegistration.
		// Must opt-in explicitly.
		$this->objectSaver->expects($this->never())->method('saveObject');
		$this->loginService->expects($this->never())->method('authenticate');
		$this->sessionLogin->expects($this->never())->method('establish');

		$this->expectException(HttpForbiddenException::class);

		($this->action)(
			$this->createRequest([]),
			$this->createMock(ResponseInterface::class),
			['collection' => 'admin'],
		);
	}

	public function testHappyPathWithoutVerificationSavesAuthenticatesAndReturnsJsonItem(): void
	{
		$this->withCollection('members', requireVerification: false);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();

		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static fn (array $d): bool => ($d['email'] ?? '') === 'a@b.test'))
			->willReturn($saved);

		$this->loginService->expects($this->once())
			->method('authenticate')
			->with('a@b.test', 'sekret123', 'members')
			->willReturn(['id' => 'alice']);

		$this->sessionLogin->expects($this->once())
			->method('establish')
			->with('alice', 'members');

		// Verification service must NOT be called.
		$this->verificationService->expects($this->never())->method('createVerificationToken');

		// Response shape mirrors ObjectSaveAction.
		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->anything(), $saved, $this->isInstanceOf(ObjectMetaTransformer::class))
			->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	public function testSaveFailureBubblesUpAsForSlimErrorHandler(): void
	{
		$this->withCollection('members', requireVerification: false);

		// Match ObjectSaveAction's behaviour: don't catch — Slim's error
		// middleware renders the exception as JSON for the AJAX client.
		$this->objectSaver->method('saveObject')
			->willThrowException(new \DomainException('Email already in use'));

		$this->loginService->expects($this->never())->method('authenticate');
		$this->sessionLogin->expects($this->never())->method('establish');
		$this->renderer->expects($this->never())->method('jsonItem');

		$this->expectException(\DomainException::class);

		($this->action)(
			$this->createRequest(['email' => 'taken@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testAutoLoginFailureIsSilentResponseStillReturnsUser(): void
	{
		$this->withCollection('members', requireVerification: false);

		// Save succeeds, then authenticate throws. The user record exists, so
		// the client still gets the JSON payload — it'll just discover the
		// missing session when the next request hits an auth-required endpoint.
		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->method('saveObject')->willReturn($saved);

		$this->loginService->method('authenticate')
			->willThrowException(new \RuntimeException('auth backend down'));

		$this->sessionLogin->expects($this->never())->method('establish');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->anything(), $saved, $this->isInstanceOf(ObjectMetaTransformer::class))
			->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	public function testVerificationModeForcesInactiveSendsEmailAndSkipsAutoLogin(): void
	{
		$this->withCollection('members', requireVerification: true);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();

		// The action MUST force active=false before passing to objectSaver,
		// regardless of what the form posted.
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with(
				'members',
				$this->callback(static fn (array $d): bool => ($d['active'] ?? null) === false && ($d['email'] ?? '') === 'a@b.test'),
			)
			->willReturn($saved);

		// Verification email sent: token created, then twig rendered, then sender called.
		$this->verificationService->expects($this->once())
			->method('createVerificationToken')
			->with('a@b.test', 'members')
			->willReturn(OperationResult::success('ok', ['token' => 'tok-xyz']));

		$this->twigEngine->expects($this->once())
			->method('render')
			->with(
				'email/verify-email.twig',
				$this->callback(static fn (array $vars): bool => str_contains((string)($vars['verifyUrl'] ?? ''), 'tok-xyz')),
			)
			->willReturn('<html>verify email body</html>');

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(static fn (array $msg): bool => ($msg['to'] ?? '') === 'a@b.test' && ($msg['subject'] ?? '') === 'Verify Your Email'))
			->willReturn(OperationResult::success('sent'));

		// Auto-login must NOT happen.
		$this->loginService->expects($this->never())->method('authenticate');
		$this->sessionLogin->expects($this->never())->method('establish');

		// Response carries the requiresVerification meta flag.
		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with(
				$this->anything(),
				$saved,
				$this->isInstanceOf(ObjectMetaTransformer::class),
				['requiresVerification' => true],
			)
			->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123', 'active' => true]),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	public function testVerificationModeUsesCustomMailerWhenConfigured(): void
	{
		$this->config->auth['verificationMailerId'] = 'custom-mailer-id';

		$this->withCollection('members', requireVerification: true);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->method('saveObject')->willReturn($saved);

		$this->verificationService->method('createVerificationToken')
			->willReturn(OperationResult::success('ok', ['token' => 'tok-zzz']));

		// Custom mailer path: emailService is used, default twig template is NOT rendered.
		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with(
				'custom-mailer-id',
				$this->callback(static fn (array $vars): bool => ($vars['email'] ?? '') === 'a@b.test' && str_contains((string)($vars['verifyUrl'] ?? ''), 'tok-zzz')),
			)
			->willReturn(OperationResult::success('sent'));

		$this->twigEngine->expects($this->never())->method('render');
		$this->emailSender->expects($this->never())->method('send');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('jsonItem')->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	public function testVerificationModeWithoutEmailFieldStillSavesButSkipsEmail(): void
	{
		// Edge case: a custom auth schema without an `email` field. The account
		// still gets created (inactive), no email is sent, response still
		// carries requiresVerification — operator's job to ensure schema has
		// email if they want this flow to work.
		$this->withCollection('members', requireVerification: true);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static fn (array $d): bool => ($d['active'] ?? null) === false))
			->willReturn($saved);

		// No email → no token, no rendering, no sending.
		$this->verificationService->expects($this->never())->method('createVerificationToken');
		$this->twigEngine->expects($this->never())->method('render');
		$this->emailSender->expects($this->never())->method('send');
		$this->emailService->expects($this->never())->method('sendEmail');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->anything(), $saved, $this->anything(), ['requiresVerification' => true])
			->willReturn($jsonResponse);

		($this->action)(
			$this->createRequest(['username' => 'alice', 'password' => 'sekret']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testVerificationModeTokenFailureSilentlySkipsEmail(): void
	{
		$this->withCollection('members', requireVerification: true);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->method('saveObject')->willReturn($saved);

		// Token creation fails (cache layer down, etc.) — account still exists,
		// no email sent, response unchanged. User can use forgot-password to
		// effectively trigger a fresh verify-style flow.
		$this->verificationService->method('createVerificationToken')
			->willReturn(OperationResult::failure('cache backend unavailable'));

		$this->twigEngine->expects($this->never())->method('render');
		$this->emailSender->expects($this->never())->method('send');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('jsonItem')->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	private function withCollection(string $id, bool $requireVerification): void
	{
		$collection                           = new CollectionData();
		$collection->id                       = $id;
		$collection->schema                   = 'auth';
		$collection->requireEmailVerification = $requireVerification;

		$this->collectionFetcher->method('fetchCollection')
			->with($id)
			->willReturn($collection);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function createRequest(array $body): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn($body);

		return $request;
	}
}

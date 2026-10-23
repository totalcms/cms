<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Auth;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\NullLogger;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Response;
use TotalCMS\Action\Auth\AuthRegisterSubmitAction;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\AuthMailer;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\RegistrationCollections;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\BotProtection;
use TotalCMS\Domain\Security\BotSubmissionGate;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class AuthRegisterSubmitActionTest extends TestCase
{
	private AuthRegisterSubmitAction $action;
	private MockObject $renderer;
	private MockObject $objectSaver;
	private MockObject $loginService;
	private MockObject $sessionLogin;
	private MockObject $collectionFetcher;
	private MockObject $verificationService;
	private MockObject $emailService;
	private MockObject $emailSender;
	private MockObject $twigEngine;
	private Config $config;
	private MockObject $objectFactory;
	private AccessGuard $guard;
	private ActorContext $actors;
	private SchemaFetcher $schemaFetcher;

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
			'publicRegistrationGroup' => '',
			'verificationTokenExpiry' => 1440,
			'verificationMailerId'    => '',
		];
		$this->config->url = 'https://example.test';
		$this->config->api = '/api';

		// Real AccessGuard: the 'members' schema carries the privileged
		// fields so stripProtected() actually removes them.
		$schema             = new SchemaData();
		$schema->id         = 'auth';
		$schema->properties = array_fill_keys(
			['groups', 'active', 'expiration', 'maxLoginCount', 'passkeys', 'name', 'email', 'password'],
			[],
		);
		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);
		// The collections here use the `auth` schema, so the toggle counts.
		$schemaFetcher->method('isOrInherits')->willReturnCallback(static fn (string $schemaId, string $target): bool => $schemaId === $target);
		$policyLoggerFactory = $this->createMock(LoggerFactory::class);
		$policyLoggerFactory->method('channelLogger')->willReturn(new NullLogger());
		$ownerProperties = $this->createMock(OwnerPropertyResolver::class);
		$ownerProperties->method('resolve')->willReturn('');
		$this->schemaFetcher   = $schemaFetcher;
		$this->objectFactory   = $this->createMock(ObjectFactory::class);
		$this->actors          = new ActorContext();
		$this->guard           = new AccessGuard(
			$schemaFetcher,
			$this->createMock(ObjectFetcher::class),
			$ownerProperties,
			$this->config,
			$policyLoggerFactory,
		);

		$this->action = $this->makeAction();
	}

	/** The action, with a real BotSubmissionGate over the given session (null: anonymous). */
	private function makeAction(?SessionInterface $session = null): AuthRegisterSubmitAction
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());
		$apiKeys = $this->createMock(ApiKeyAuthenticator::class);
		$apiKeys->method('hasApiKeyHeader')->willReturn(false);

		return new AuthRegisterSubmitAction(
			$this->renderer,
			$this->objectSaver,
			$this->loginService,
			$this->sessionLogin,
			$this->guard,
			$this->actors,
			$this->collectionFetcher,
			$this->verificationService,
			new AuthMailer($this->emailService, $this->emailSender, $this->twigEngine, $this->createMock(UserValidationService::class), $this->config),
			$this->config,
			// RegistrationCollections is final, so it cannot be mocked: a real one
			// over the same Config and fetcher mock. The gate and the groups then
			// follow `$this->config->auth` and the collection built by withCollection().
			new RegistrationCollections($this->config, $this->collectionFetcher, $this->schemaFetcher),
			new BotSubmissionGate(
				new BotProtection('test-key'),
				$this->collectionFetcher,
				$this->objectFactory,
				$this->createMock(ObjectFetcher::class),
				$apiKeys,
				$session,
				$loggerFactory,
			),
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

	public function testStripsPrivilegedFieldsAndForcesDefaultsWithoutVerification(): void
	{
		$this->config->auth['publicRegistrationGroup'] = 'member';
		$this->withCollection('members', requireVerification: false);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();

		// The attacker submits privileged fields; the action must strip them and
		// force the server-side group + active state.
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static function (array $d): bool {
				expect($d['groups'])->toBe(['member']);              // forced, not ['admin']
				expect($d['active'])->toBe(true);                    // forced (non-verification)
				expect($d)->not->toHaveKey('expiration');            // stripped
				expect($d)->not->toHaveKey('maxLoginCount');         // stripped
				expect($d)->not->toHaveKey('passkeys');              // stripped
				expect($d['email'])->toBe('a@b.test');               // user field kept

				return true;
			}))
			// The save runs as the system actor: the storage layer must not
			// strip the groups and active state the action just forced.
			->willReturnCallback(function () use ($saved): ObjectData {
				expect($this->actors->current()->kind)->toBe(ActorKind::System);

				return $saved;
			});

		$this->loginService->method('authenticate')->willReturn(['id' => 'alice']);
		$this->renderer->method('jsonItem')->willReturn($this->createMock(ResponseInterface::class));

		($this->action)(
			$this->createRequest([
				'email'         => 'a@b.test',
				'password'      => 'sekret123',
				'groups'        => ['admin'],
				'active'        => true,
				'expiration'    => '2099-01-01',
				'maxLoginCount' => 999,
				'passkeys'      => [['id' => 'forged']],
			]),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testForcesEmptyGroupWhenNoDefaultConfigured(): void
	{
		// publicRegistrationGroup defaults to '' in setUp.
		$this->withCollection('members', requireVerification: false);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static function (array $d): bool {
				expect($d['groups'])->toBe([]); // no group granted

				return true;
			}))
			->willReturn($saved);

		$this->loginService->method('authenticate')->willReturn(['id' => 'alice']);
		$this->renderer->method('jsonItem')->willReturn($this->createMock(ResponseInterface::class));

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123', 'groups' => ['admin']]),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
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

	public function testVerifyRedirectReachesTheTokenAndIsNeverSavedOnTheUser(): void
	{
		$this->withCollection('members', requireVerification: true);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static function (array $d): bool {
				expect($d)->not->toHaveKey('verifyRedirect'); // the token's, not the user's

				return true;
			}))
			->willReturn($saved);

		$this->verificationService->expects($this->once())
			->method('createVerificationToken')
			->with('a@b.test', 'members', '/welcome')
			->willReturn(OperationResult::failure('skip the email'));

		$this->renderer->method('jsonItem')->willReturn($this->createMock(ResponseInterface::class));

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123', 'verifyRedirect' => '/welcome']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testSameHostAbsoluteVerifyRedirectIsKept(): void
	{
		$this->withCollection('members', requireVerification: true);

		$this->objectSaver->method('saveObject')
			->willReturn((new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor());

		$this->verificationService->expects($this->once())
			->method('createVerificationToken')
			->with('a@b.test', 'members', 'https://example.test/welcome')
			->willReturn(OperationResult::failure('skip the email'));

		$this->renderer->method('jsonItem')->willReturn($this->createMock(ResponseInterface::class));

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123', 'verifyRedirect' => 'https://example.test/welcome']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testOffSiteVerifyRedirectIsDropped(): void
	{
		$this->withCollection('members', requireVerification: true);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static fn (array $d): bool => !array_key_exists('verifyRedirect', $d)))
			->willReturn($saved);

		// Off-site target: the token gets no redirect, so the link lands on the
		// login page exactly as it does without one.
		$this->verificationService->expects($this->once())
			->method('createVerificationToken')
			->with('a@b.test', 'members', '')
			->willReturn(OperationResult::failure('skip the email'));

		$this->renderer->method('jsonItem')->willReturn($this->createMock(ResponseInterface::class));

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123', 'verifyRedirect' => 'https://evil.example/phish']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testVerifyRedirectIsStrippedEvenWithoutVerification(): void
	{
		$this->withCollection('members', requireVerification: false);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static fn (array $d): bool => !array_key_exists('verifyRedirect', $d)))
			->willReturn($saved);

		$this->loginService->method('authenticate')->willReturn(['id' => 'alice']);
		$this->renderer->method('jsonItem')->willReturn($this->createMock(ResponseInterface::class));

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123', 'verifyRedirect' => '/welcome']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testToggleOnlyCollectionRegisters(): void
	{
		// Not in the config allow-list: the collection's own toggle opens it.
		$this->config->auth['publicRegistration'] = [];
		$this->withCollection('members', requireVerification: false, publicRegistration: true);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->anything())
			->willReturn($saved);

		$this->loginService->method('authenticate')->willReturn(['id' => 'alice']);
		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('jsonItem')->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	public function testRejectsCollectionWithToggleOffAndNotInAllowList(): void
	{
		$this->config->auth['publicRegistration'] = [];
		$this->withCollection('members', requireVerification: false, publicRegistration: false, registrationGroups: ['vip']);

		$this->objectSaver->expects($this->never())->method('saveObject');

		$this->expectException(HttpForbiddenException::class);
		$this->expectExceptionMessage('Public registration is not enabled for this collection.');

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testGroupsAreTheUnionOfGlobalAndCollectionGroupsIgnoringSubmitted(): void
	{
		$this->config->auth['publicRegistrationGroup'] = 'member';
		$this->withCollection('members', requireVerification: false, registrationGroups: ['vip', 'member']);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static function (array $d): bool {
				expect($d['groups'])->toBe(['member', 'vip']); // not ['admin']

				return true;
			}))
			->willReturn($saved);

		$this->loginService->method('authenticate')->willReturn(['id' => 'alice']);
		$this->renderer->method('jsonItem')->willReturn($this->createMock(ResponseInterface::class));

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123', 'groups' => ['admin']]),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	// -------------------------------------------------------------------
	// Bot protection (honeypot + time trap)
	// -------------------------------------------------------------------

	public function testValidBotTokenRegistersAndTheBotFieldsAreNotSaved(): void
	{
		$this->withCollection('members', requireVerification: false, requireBotProtection: true);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static function (array $d): bool {
				expect($d)->not->toHaveKey(BotProtection::HONEYPOT_FIELD);
				expect($d)->not->toHaveKey(BotProtection::TIMESTAMP_FIELD);
				expect($d['email'])->toBe('a@b.test');

				return true;
			}))
			->willReturn($saved);
		$this->objectFactory->expects($this->never())->method('generateObject');

		$this->loginService->method('authenticate')->willReturn(['id' => 'alice']);
		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->anything(), $saved, $this->isInstanceOf(ObjectMetaTransformer::class))
			->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest($this->botBody(time() - 10)),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	public function testFilledHoneypotGetsAFakeVerificationSuccessWithNoSaveAndNoEmail(): void
	{
		$this->withCollection('members', requireVerification: false);
		$this->expectFakeSuccess();

		($this->action)(
			$this->createRequest($this->botBody(time() - 10, honeypot: 'https://spam.example')),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testFakeSuccessCarriesTheVerificationFlagWhenTheCollectionVerifies(): void
	{
		$this->withCollection('members', requireVerification: true);
		$this->expectFakeSuccess(verification: true);

		($this->action)(
			$this->createRequest($this->botBody(time() - 10, honeypot: 'https://spam.example')),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testExpiredTokenGetsAVisible422AndNothingIsSaved(): void
	{
		$this->withCollection('members', requireVerification: true);

		$this->objectSaver->expects($this->never())->method('saveObject');
		$this->objectFactory->expects($this->never())->method('generateObject');
		$this->verificationService->expects($this->never())->method('createVerificationToken');
		$this->renderer->expects($this->never())->method('jsonItem');

		$result = ($this->action)(
			$this->createRequest($this->botBody(time() - BotProtection::MAX_AGE_SECONDS - 60)),
			new Response(),
			['collection' => 'members'],
		);

		$this->assertSame(422, $result->getStatusCode());
		$this->assertSame(['error' => ['message' => BotSubmissionGate::EXPIRED_MESSAGE]], json_decode((string)$result->getBody(), true));
	}

	public function testTooFastSubmissionGetsAFakeSuccess(): void
	{
		$this->withCollection('members', requireVerification: false);
		$this->expectFakeSuccess();

		($this->action)(
			$this->createRequest($this->botBody(time())),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testNoBotFieldsRegistersWhenTheCollectionDoesNotRequireThem(): void
	{
		$this->withCollection('members', requireVerification: false);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())->method('saveObject')->willReturn($saved);
		$this->objectFactory->expects($this->never())->method('generateObject');
		$this->loginService->method('authenticate')->willReturn(['id' => 'alice']);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->anything(), $saved, $this->isInstanceOf(ObjectMetaTransformer::class))
			->willReturn($this->createMock(ResponseInterface::class));

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testNoBotFieldsGetsAFakeSuccessWhenTheCollectionRequiresThem(): void
	{
		$this->withCollection('members', requireVerification: false, requireBotProtection: true);
		$this->expectFakeSuccess();

		($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testSignedInSessionSkipsBotProtectionEvenWhenRequired(): void
	{
		$this->withCollection('members', requireVerification: false, requireBotProtection: true);

		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'admin-user' : null);
		$action = $this->makeAction($session);

		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static fn (array $d): bool => !array_key_exists(BotProtection::HONEYPOT_FIELD, $d)))
			->willReturn($saved);
		$this->objectFactory->expects($this->never())->method('generateObject');
		$this->loginService->method('authenticate')->willReturn(['id' => 'alice']);
		$this->renderer->method('jsonItem')->willReturn($this->createMock(ResponseInterface::class));

		// No timestamp, and a filled honeypot that is still stripped.
		$action(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123', BotProtection::HONEYPOT_FIELD => 'x']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	/**
	 * Fake success: the decoy object goes out with requiresVerification,
	 * built from the stripped data with active forced off; nothing is saved,
	 * no one is logged in, no email is sent.
	 */
	private function expectFakeSuccess(bool $verification = false): void
	{
		$decoy = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectFactory->expects($this->once())
			->method('generateObject')
			->with('members', $this->callback(static function (array $d) use ($verification): bool {
				expect($d)->not->toHaveKey(BotProtection::HONEYPOT_FIELD);
				expect($d)->not->toHaveKey(BotProtection::TIMESTAMP_FIELD);
				expect($d['active'])->toBe(!$verification); // as a real signup would store it
				expect($d['groups'])->toBe([]);

				return true;
			}))
			->willReturn($decoy);

		$this->objectSaver->expects($this->never())->method('saveObject');
		$this->loginService->expects($this->never())->method('authenticate');
		$this->sessionLogin->expects($this->never())->method('establish');
		$this->verificationService->expects($this->never())->method('createVerificationToken');
		$this->emailSender->expects($this->never())->method('send');
		$this->emailService->expects($this->never())->method('sendEmail');

		// The same response a real signup gets: the verification flag only
		// when the collection verifies.
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->willReturnCallback(function (ResponseInterface $response, object $item, object $transformer, array $meta = []) use ($decoy, $verification): ResponseInterface {
				$this->assertSame($decoy, $item);
				$this->assertInstanceOf(ObjectMetaTransformer::class, $transformer);
				$this->assertSame($verification ? ['requiresVerification' => true] : [], $meta);

				return $this->createMock(ResponseInterface::class);
			});
	}

	/** @return array<string,mixed> */
	private function botBody(int $issuedAt, string $honeypot = ''): array
	{
		return [
			'email'                        => 'a@b.test',
			'password'                     => 'sekret123',
			BotProtection::HONEYPOT_FIELD  => $honeypot,
			BotProtection::TIMESTAMP_FIELD => (new BotProtection('test-key'))->issue('members', 'register', $issuedAt),
		];
	}

	/**
	 * @param list<string> $registrationGroups
	 */
	private function withCollection(string $id, bool $requireVerification, bool $publicRegistration = false, array $registrationGroups = [], bool $requireBotProtection = false): void
	{
		$collection                           = new CollectionData();
		$collection->id                       = $id;
		$collection->schema                   = 'auth';
		$collection->requireEmailVerification = $requireVerification;
		$collection->publicRegistration       = $publicRegistration;
		$collection->registrationGroups       = $registrationGroups;
		$collection->requireBotProtection     = $requireBotProtection;

		$this->collectionFetcher->method('fetchCollection')
			->with($id)
			->willReturn($collection);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function createRequest(array $body, string $host = 'example.test'): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getHost')->willReturn($host);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn($body);
		$request->method('getUri')->willReturn($uri);

		return $request;
	}
}

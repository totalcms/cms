<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Action\Auth\AuthVerifyEmailAction;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

// Where the verification link lands. Without a redirect on the token: the
// (collection-scoped) login page, as always. With one: the site's own page,
// re-checked same-origin here. An expired link keeps the redirect on its way
// to the resend form.

beforeEach(function (): void {
	$this->verification = $this->createMock(EmailVerificationService::class);
	$this->session      = new PhpSession();

	$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->auth = ['collection' => 'auth'];

	$translator = $this->createMock(TranslationService::class);
	$translator->method('trans')->willReturnArgument(0);

	$this->action = new AuthVerifyEmailAction($this->verification, $config, $this->session, $translator);

	$parser = $this->createMock(RouteParserInterface::class);
	$parser->method('urlFor')->willReturnCallback(
		static fn (string $name, array $data = []): string => '/admin/' . $name . (isset($data['collection']) ? '/' . $data['collection'] : ''),
	);
	$this->parser  = $parser;
	$this->routing = $this->createMock(RoutingResults::class);
});

function verifyRequest(mixed $test): ServerRequestInterface
{
	return (new ServerRequestFactory())->createServerRequest('GET', 'https://example.test/admin/verify-email/tok')
		->withAttribute(RouteContext::ROUTE_PARSER, $test->parser)
		->withAttribute(RouteContext::ROUTING_RESULTS, $test->routing);
}

function verifyRun(mixed $test): ResponseInterface
{
	return ($test->action)(verifyRequest($test), (new ResponseFactory())->createResponse(), ['token' => 'tok']);
}

/** @param array<string,mixed> $data */
function tokenDecodes(mixed $test, array $data, bool $valid = true): void
{
	$test->verification->method('validateToken')->willReturn(
		$valid ? OperationResult::success('ok', $data) : OperationResult::failure('This token has expired.', null, $data),
	);
}

describe('AuthVerifyEmailAction', function (): void {
	test('success with a redirect on the token lands there, flash kept', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'members', 'redirect' => '/welcome']);
		$this->verification->method('activateUser')->willReturn(OperationResult::success('ok'));

		$response = verifyRun($this);

		expect($response->getStatusCode())->toBe(302)
			->and($response->getHeaderLine('Location'))->toBe('/welcome')
			->and($this->session->getFlash()->get('success'))->toBe(['flash.email_verified']);
	});

	test('success with a same-host absolute redirect lands there', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'members', 'redirect' => 'https://example.test/welcome']);
		$this->verification->method('activateUser')->willReturn(OperationResult::success('ok'));

		expect(verifyRun($this)->getHeaderLine('Location'))->toBe('https://example.test/welcome');
	});

	test('success without a redirect lands on the collection login, as before', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'members']);
		$this->verification->method('activateUser')->willReturn(OperationResult::success('ok'));

		expect(verifyRun($this)->getHeaderLine('Location'))->toBe('/admin/login/members');
	});

	test('success without a redirect in the default collection lands on the plain login', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'auth']);
		$this->verification->method('activateUser')->willReturn(OperationResult::success('ok'));

		expect(verifyRun($this)->getHeaderLine('Location'))->toBe('/admin/login');
	});

	test('a token redirect that fails the host check falls back to the login', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'members', 'redirect' => 'https://evil.example/phish']);
		$this->verification->method('activateUser')->willReturn(OperationResult::success('ok'));

		expect(verifyRun($this)->getHeaderLine('Location'))->toBe('/admin/login/members');
	});

	test('an expired token carrying a redirect keeps it on the way to the resend form', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'members', 'redirect' => '/welcome?a=1'], valid: false);
		$this->verification->method('activateUser')->willReturn(OperationResult::failure('expired'));

		$response = verifyRun($this);

		expect($response->getHeaderLine('Location'))->toBe('/admin/resend-verification/members?redirect=%2Fwelcome%3Fa%3D1')
			->and($this->session->getFlash()->get('error'))->toBe(['flash.email_verification_failed']);
	});

	test('an expired token without a redirect goes to its own collection\'s resend form', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'members'], valid: false);
		$this->verification->method('activateUser')->willReturn(OperationResult::failure('expired'));

		expect(verifyRun($this)->getHeaderLine('Location'))->toBe('/admin/resend-verification/members');
	});

	test('an expired default-collection token without a redirect goes to the plain resend form', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'auth'], valid: false);
		$this->verification->method('activateUser')->willReturn(OperationResult::failure('expired'));

		expect(verifyRun($this)->getHeaderLine('Location'))->toBe('/admin/resend-verification');
	});

	test('a token that does not decode goes to the default resend form', function (): void {
		$this->verification->method('validateToken')->willReturn(OperationResult::failure('Invalid or expired token.'));
		$this->verification->method('activateUser')->willReturn(OperationResult::failure('Invalid or expired token.'));

		expect(verifyRun($this)->getHeaderLine('Location'))->toBe('/admin/resend-verification');
	});

	test('a valid token whose activation fails keeps the redirect on the resend URL', function (): void {
		tokenDecodes($this, ['email' => 'a@b.test', 'collection' => 'auth', 'redirect' => '/welcome']);
		$this->verification->method('activateUser')->willReturn(OperationResult::failure('User account not found.'));

		expect(verifyRun($this)->getHeaderLine('Location'))->toBe('/admin/resend-verification?redirect=%2Fwelcome');
	});
});

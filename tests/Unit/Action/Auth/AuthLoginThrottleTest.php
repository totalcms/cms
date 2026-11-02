<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Action\Auth\AuthLoginSubmitAction;
use TotalCMS\Domain\Auth\Exception\InvalidCredentialsException;
use TotalCMS\Domain\Auth\Service\AuthThrottle;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthLoginCollection;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

// The Nov 2026 ZAP scan sent 434 logins without keeping a cookie, and every
// one reached the password check because the count lived in the session.
// Here each request gets a brand-new session, as that bot's did.

beforeEach(function (): void {
	$this->config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$this->config->auth = ['collection' => 'auth'];

	$parser = $this->createMock(RouteParserInterface::class);
	$parser->method('urlFor')->willReturnCallback(static fn (string $name): string => '/admin/' . $name);
	$this->parser  = $parser;
	$this->routing = $this->createMock(RoutingResults::class);

	$this->throttle = testAuthThrottle(['maxAttempts' => 2, 'deniedTimeout' => 7]);
	$this->login    = $this->createMock(LoginService::class);

	$this->translator = $this->createMock(TranslationService::class);
	$this->translator->method('trans')->willReturnArgument(0);
	$this->sessionLogin = $this->createMock(SessionLogin::class);
	$this->persistent   = $this->createMock(PersistentLoginService::class);
});

/** One login POST with a fresh session, from the given address. */
function cookielessLogin(object $test, AuthThrottle $throttle, string $ip = '203.0.113.5'): ResponseInterface
{
	$action = new AuthLoginSubmitAction(
		new PhpSession(),
		$test->login,
		$test->sessionLogin,
		$test->config,
		$test->persistent,
		$test->translator,
		new OAuthLoginCollection($test->config, new OAuthActivityLogger(new NullLogger())),
		$throttle,
	);

	$request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/admin/login', ['REMOTE_ADDR' => $ip])
		->withParsedBody(['email' => 'admin@example.test', 'password' => 'guess'])
		->withAttribute(RouteContext::ROUTE_PARSER, $test->parser)
		->withAttribute(RouteContext::ROUTING_RESULTS, $test->routing);

	return $action($request, (new ResponseFactory())->createResponse(), []);
}

test('a client without cookies stops reaching the password check at the limit', function (): void {
	$this->login->expects($this->exactly(2))->method('authenticate')
		->willThrowException(new InvalidCredentialsException('Invalid credentials'));

	for ($i = 0; $i < 4; $i++) {
		expect(cookielessLogin($this, $this->throttle)->getStatusCode())->toBe(302);
	}

	expect($this->throttle->loginLockedFor(
		(new ServerRequestFactory())->createServerRequest('POST', '/', ['REMOTE_ADDR' => '203.0.113.5']),
	))->toBeGreaterThan(0);
});

test('a lockout is per address: another client still gets checked', function (): void {
	$this->login->expects($this->exactly(3))->method('authenticate')
		->willThrowException(new InvalidCredentialsException('Invalid credentials'));

	cookielessLogin($this, $this->throttle);
	cookielessLogin($this, $this->throttle);
	cookielessLogin($this, $this->throttle);
	cookielessLogin($this, $this->throttle, '198.51.100.9');
});

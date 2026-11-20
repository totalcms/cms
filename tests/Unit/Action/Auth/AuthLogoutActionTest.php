<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Auth;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TotalCMS\Action\Auth\AuthLogoutAction;
use TotalCMS\Bundled\Protect\ProtectMiddleware;
use TotalCMS\Domain\Auth\Service\LogoutService;
use TotalCMS\Domain\Builder\Data\PageData;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/protect/ProtectMiddleware.php';

/** Logging out locks the Protect extension's passcode pages again. */
final class AuthLogoutActionTest extends TestCase
{
	private const SECRET = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

	public function testThePrefixIsProtects(): void
	{
		self::assertSame(ProtectMiddleware::COOKIE_PREFIX, AuthLogoutAction::PROTECT_COOKIE_PREFIX);
	}

	public function testLogoutExpiresTheCookieAPasscodeSet(): void
	{
		$factory = new Psr17Factory();

		// Unlock a page the way a visitor does, and keep the cookie Protect sets.
		$unlock = (new ProtectMiddleware(self::SECRET))->handle(
			$factory->createServerRequest('POST', 'https://example.com/clients')->withParsedBody(['passcode' => '8675']),
			new PageData(['id' => 'clients', 'data' => ['passcode' => '8675']]),
		);
		self::assertNotNull($unlock);
		[$pair]         = explode(';', $unlock->getHeaderLine('Set-Cookie'), 2);
		[$name, $value] = explode('=', $pair, 2);

		$response = $this->logout(['PHPSESSID' => 'abc', $name => $value, 'tcms_protect_bad name' => 'x']);

		self::assertSame(302, $response->getStatusCode());
		self::assertSame(
			[$name . '=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/; SameSite=Lax; HttpOnly; Secure'],
			$response->getHeader('Set-Cookie'),
		);
	}

	public function testLogoutWithoutAnUnlockSetsNoCookie(): void
	{
		self::assertSame([], $this->logout(['PHPSESSID' => 'abc'])->getHeader('Set-Cookie'));
	}

	/** @param array<string,string> $cookies */
	private function logout(array $cookies): ResponseInterface
	{
		$factory = new Psr17Factory();
		$request = $factory->createServerRequest('GET', 'https://example.com/admin/logout')->withCookieParams($cookies);

		return (new AuthLogoutAction($this->createStub(LogoutService::class)))($request, $factory->createResponse());
	}
}

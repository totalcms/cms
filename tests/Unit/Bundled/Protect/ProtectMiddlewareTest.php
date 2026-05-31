<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\Protect;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TotalCMS\Bundled\Protect\ProtectMiddleware;
use TotalCMS\Domain\Builder\Data\PageData;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/protect/ProtectMiddleware.php';

final class ProtectMiddlewareTest extends TestCase
{
	/** Fixed test secret — 64 hex chars (32 bytes). */
	private const TEST_SECRET = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

	private ProtectMiddleware $middleware;
	private Psr17Factory $psr17;

	protected function setUp(): void
	{
		$this->middleware = new ProtectMiddleware(self::TEST_SECRET);
		$this->psr17      = new Psr17Factory();
	}

	// --- attaching the middleware gates the page (no passcode required to activate) ---
	//
	// Like Maintenance, attaching `protect` is what gates the page. With no
	// passcode configured anywhere the page fails CLOSED: the public sees the
	// prompt but can never enter a code that doesn't exist, so it's effectively
	// operators-only (logged-in admins bypass — see the admin-bypass section).

	public function testNoPasscodeGatesAsOperatorsOnly(): void
	{
		$response = $this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', []),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
		$this->assertStringContainsString('name="passcode"', (string)$response->getBody());
	}

	public function testEmptyPasscodeGatesAsOperatorsOnly(): void
	{
		$response = $this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => '']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	public function testNonStringPasscodeGatesAsOperatorsOnly(): void
	{
		$response = $this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => 12345]),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	// --- admin bypass (logged-in operators preview the page, like Maintenance) ---

	public function testLoggedInAdminBypassesGate(): void
	{
		$middleware = new ProtectMiddleware(self::TEST_SECRET, isAdmin: static fn (): bool => true);

		$this->assertNull($middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => '1234']),
		));
	}

	public function testAdminBypassesEvenWhenNoPasscodeConfigured(): void
	{
		$middleware = new ProtectMiddleware(self::TEST_SECRET, isAdmin: static fn (): bool => true);

		$this->assertNull($middleware->handle(
			$this->get('/preview'),
			$this->page('preview', []),
		));
	}

	public function testNonAdminVisitorIsGated(): void
	{
		// Logged-out visitors AND front-end members both resolve to false here.
		$middleware = new ProtectMiddleware(self::TEST_SECRET, isAdmin: static fn (): bool => false);

		$response = $middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	public function testNoAdminCheckGates(): void
	{
		// Default construction (no isAdmin closure) — safe default is to gate.
		$response = $this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	// --- prompt rendering ---

	public function testGetWithoutCookieShowsPrompt(): void
	{
		$response = $this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
		$body = (string)$response->getBody();
		$this->assertStringContainsString('Enter passcode to view', $body);
		$this->assertStringContainsString('name="passcode"', $body);
	}

	public function testCustomPromptTitle(): void
	{
		$response = $this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => '1234', 'promptTitle' => 'Client Preview']),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('Client Preview', $body);
	}

	// --- H9: XSS escaping in prompt title ---

	public function testPromptTitleIsHtmlEscaped(): void
	{
		$response = $this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => '1234', 'promptTitle' => '<script>alert(1)</script>']),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringNotContainsString('<script>', $body);
		$this->assertStringContainsString('&lt;script&gt;', $body);
	}

	// --- cookie validation ---

	public function testValidCookiePassesThrough(): void
	{
		$passcode = '8675';
		$pageId   = 'preview';
		$hmac     = hash_hmac('sha256', $pageId . ':' . $passcode, self::TEST_SECRET);

		$request = $this->get('/preview', [
			ProtectMiddleware::COOKIE_PREFIX . $pageId => $hmac,
		]);

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page($pageId, ['passcode' => $passcode]),
		));
	}

	public function testInvalidCookieShowsPrompt(): void
	{
		$request = $this->get('/preview', [
			ProtectMiddleware::COOKIE_PREFIX . 'preview' => 'bogus',
		]);

		$response = $this->middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	public function testCookieFromDifferentPasscodeIsRejected(): void
	{
		$oldHmac = hash_hmac('sha256', 'preview:1111', self::TEST_SECRET);

		$request = $this->get('/preview', [
			ProtectMiddleware::COOKIE_PREFIX . 'preview' => $oldHmac,
		]);

		$response = $this->middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '2222']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	// --- H7: cookie is page-scoped (pageId is part of the signed message) ---

	public function testCookieFromDifferentPageIsRejected(): void
	{
		// Compute a valid cookie for page "other-page" with passcode "1234".
		$otherPageHmac = hash_hmac('sha256', 'other-page:1234', self::TEST_SECRET);

		// Present that cookie as if it were for page "preview".
		$request = $this->get('/preview', [
			ProtectMiddleware::COOKIE_PREFIX . 'preview' => $otherPageHmac,
		]);

		$response = $this->middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	// --- scope groups (one passcode unlocks a group of pages) ---

	public function testProtectScopeSetsScopedCookieName(): void
	{
		$response = $this->middleware->handle(
			$this->post('/about', ['passcode' => '1234']),
			$this->page('about', ['passcode' => '1234', 'protectScope' => 'vip']),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$cookie = $response->getHeaderLine('Set-Cookie');
		// Cookie is keyed by the scope, not the page id.
		$this->assertStringContainsString('tcms_protect_vip=', $cookie);
		$this->assertStringNotContainsString('tcms_protect_about=', $cookie);
	}

	public function testSharedScopeUnlocksAnotherPageInTheGroup(): void
	{
		// A cookie minted for scope "vip" + passcode "1234" unlocks ANY page that
		// shares that scope and passcode — enter the code once, unlock the group.
		$hmac    = hash_hmac('sha256', 'vip:1234', self::TEST_SECRET);
		$request = $this->get('/pricing', [
			ProtectMiddleware::COOKIE_PREFIX . 'vip' => $hmac,
		]);

		// A *different* page (id "pricing") in the same "vip" scope.
		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('pricing', ['passcode' => '1234', 'protectScope' => 'vip']),
		));
	}

	public function testCookieFromDifferentScopeIsRejected(): void
	{
		$hmac    = hash_hmac('sha256', 'groupA:1234', self::TEST_SECRET);
		$request = $this->get('/secret', [
			ProtectMiddleware::COOKIE_PREFIX . 'groupA' => $hmac,
		]);

		$response = $this->middleware->handle(
			$request,
			$this->page('secret', ['passcode' => '1234', 'protectScope' => 'groupB']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	public function testProtectScopeIsSanitizedForCookieName(): void
	{
		$response = $this->middleware->handle(
			$this->post('/about', ['passcode' => '1234']),
			$this->page('about', ['passcode' => '1234', 'protectScope' => 'Client Preview!']),
		);

		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		// Spaces and unsafe characters are stripped so the cookie name is valid.
		$this->assertStringContainsString('tcms_protect_ClientPreview=', $cookie);
	}

	public function testEmptyScopeFallsBackToPageId(): void
	{
		$response = $this->middleware->handle(
			$this->post('/about', ['passcode' => '1234']),
			$this->page('about', ['passcode' => '1234', 'protectScope' => '']),
		);

		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertStringContainsString('tcms_protect_about=', $cookie);
	}

	// --- global (site-wide) scope ---

	public function testGlobalScopeSharesOneCookieAcrossPages(): void
	{
		$mw = new ProtectMiddleware(self::TEST_SECRET, defaultPasscode: '1234', globalScope: true);

		// Unlock on one page → cookie keyed by the global scope, not the page id.
		$response = $mw->handle(
			$this->post('/about', ['passcode' => '1234']),
			$this->page('about', []),
		);
		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertStringContainsString('tcms_protect_' . ProtectMiddleware::GLOBAL_SCOPE_KEY . '=', $cookie);

		// That same cookie unlocks a completely different page.
		$hmac    = hash_hmac('sha256', ProtectMiddleware::GLOBAL_SCOPE_KEY . ':1234', self::TEST_SECRET);
		$request = $this->get('/pricing', [
			ProtectMiddleware::COOKIE_PREFIX . ProtectMiddleware::GLOBAL_SCOPE_KEY => $hmac,
		]);
		$this->assertNull($mw->handle($request, $this->page('pricing', [])));
	}

	public function testGlobalScopeIgnoresPerPagePasscode(): void
	{
		$mw   = new ProtectMiddleware(self::TEST_SECRET, defaultPasscode: '1234', globalScope: true);
		$page = $this->page('about', ['passcode' => '9999']);

		// The page sets its own passcode, but global mode uses the default.
		$ok = $mw->handle($this->post('/about', ['passcode' => '1234']), $page);
		$this->assertNotNull($ok);
		$this->assertSame(302, $ok->getStatusCode());

		$bad = $mw->handle($this->post('/about', ['passcode' => '9999']), $page);
		$this->assertNotNull($bad);
		$this->assertSame(403, $bad->getStatusCode());
	}

	public function testGlobalScopeIgnoresPerPageScope(): void
	{
		$mw = new ProtectMiddleware(self::TEST_SECRET, defaultPasscode: '1234', globalScope: true);

		$response = $mw->handle(
			$this->post('/about', ['passcode' => '1234']),
			$this->page('about', ['protectScope' => 'ignored-group']),
		);
		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertStringContainsString('tcms_protect_' . ProtectMiddleware::GLOBAL_SCOPE_KEY . '=', $cookie);
		$this->assertStringNotContainsString('tcms_protect_ignored-group=', $cookie);
	}

	// --- POST submission ---

	public function testCorrectPasscodeRedirectsWithCookie(): void
	{
		$request = $this->post('/preview', ['passcode' => '1234']);

		$response = $this->middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('/preview', $response->getHeaderLine('Location'));

		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertStringContainsString('tcms_protect_preview=', $cookie);
		$this->assertStringContainsString('HttpOnly', $cookie);
	}

	// --- H8: Secure flag on HTTPS requests ---

	public function testCookieHasSecureFlagOnHttps(): void
	{
		$request = $this->psr17->createServerRequest('POST', 'https://example.com/preview')
			->withParsedBody(['passcode' => '1234']);

		$response = $this->middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertStringContainsString('Secure', $cookie);
	}

	public function testCookieHasNoSecureFlagOnHttp(): void
	{
		$request = $this->psr17->createServerRequest('POST', 'http://localhost/preview')
			->withParsedBody(['passcode' => '1234']);

		$response = $this->middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertStringNotContainsString('Secure', $cookie);
	}

	// --- configurable cookie lifetime ---

	public function testCookieUsesConfiguredTtl(): void
	{
		$oneDay     = 86400;
		$middleware = new ProtectMiddleware(self::TEST_SECRET, cookieTtl: $oneDay);

		$response = $middleware->handle(
			$this->post('/preview', ['passcode' => '1234']),
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertStringContainsString('Expires=', $cookie);

		// The Expires date should be ~1 day out, not the 7-day default.
		$this->assertSame(1, preg_match('/Expires=([^;]+)/', (string)$cookie, $m));
		$this->assertEqualsWithDelta(time() + $oneDay, (int)strtotime($m[1]), 10);
	}

	public function testZeroTtlProducesSessionCookie(): void
	{
		$middleware = new ProtectMiddleware(self::TEST_SECRET, cookieTtl: 0);

		$response = $middleware->handle(
			$this->post('/preview', ['passcode' => '1234']),
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		// Session cookie: the value is still set, but with no Expires/Max-Age the
		// browser drops it when it closes.
		$this->assertStringContainsString('tcms_protect_preview=', $cookie);
		$this->assertStringNotContainsString('Expires=', $cookie);
		$this->assertStringNotContainsString('Max-Age=', $cookie);
	}

	public function testDefaultTtlIsSevenDays(): void
	{
		$response = $this->middleware->handle(
			$this->post('/preview', ['passcode' => '1234']),
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertSame(1, preg_match('/Expires=([^;]+)/', (string)$cookie, $m));
		$this->assertEqualsWithDelta(time() + (7 * 86400), (int)strtotime($m[1]), 10);
	}

	public function testIncorrectPasscodeShowsError(): void
	{
		$request = $this->post('/preview', ['passcode' => '9999']);

		$response = $this->middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
		$body = (string)$response->getBody();
		$this->assertStringContainsString('Incorrect passcode', $body);
	}

	public function testEmptyPostPasscodeShowsError(): void
	{
		$request = $this->post('/preview', ['passcode' => '']);

		$response = $this->middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '1234']),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	// --- extension-level defaults ---

	public function testExtensionDefaultPasscodeUsedWhenPageOmitsIt(): void
	{
		$middleware = new ProtectMiddleware(self::TEST_SECRET, defaultPasscode: '5555');

		$response = $middleware->handle(
			$this->get('/preview'),
			$this->page('preview', []),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
	}

	public function testPagePasscodeOverridesExtensionDefault(): void
	{
		$middleware = new ProtectMiddleware(self::TEST_SECRET, defaultPasscode: '5555');

		$request = $this->post('/preview', ['passcode' => '9999']);

		$response = $middleware->handle(
			$request,
			$this->page('preview', ['passcode' => '9999']),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
	}

	public function testExtensionDefaultPromptTitleUsedWhenPageOmitsIt(): void
	{
		$middleware = new ProtectMiddleware(self::TEST_SECRET, defaultPasscode: '1234', defaultPromptTitle: 'Site Preview');

		$response = $middleware->handle(
			$this->get('/preview'),
			$this->page('preview', []),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('Site Preview', $body);
	}

	public function testPagePromptTitleOverridesExtensionDefault(): void
	{
		$middleware = new ProtectMiddleware(self::TEST_SECRET, defaultPasscode: '1234', defaultPromptTitle: 'Site Preview');

		$response = $middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['promptTitle' => 'Client Access']),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('Client Access', $body);
		$this->assertStringNotContainsString('Site Preview', $body);
	}

	// --- helpers ---

	/** @param array<string,mixed> $data */
	private function page(string $id, array $data): PageData
	{
		return new PageData(['id' => $id, 'data' => $data]);
	}

	/** @param array<string,string> $cookies */
	private function get(string $path, array $cookies = []): \Psr\Http\Message\ServerRequestInterface
	{
		$request = $this->psr17->createServerRequest('GET', $path);

		if ($cookies !== []) {
			$request = $request->withCookieParams($cookies);
		}

		return $request;
	}

	/** @param array<string,string> $body */
	private function post(string $path, array $body = []): \Psr\Http\Message\ServerRequestInterface
	{
		return $this->psr17->createServerRequest('POST', $path)
			->withParsedBody($body);
	}
}

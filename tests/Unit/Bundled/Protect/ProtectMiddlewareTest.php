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
		$this->psr17     = new Psr17Factory();
	}

	// --- no-op cases ---

	public function testNoPasscodeIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', []),
		));
	}

	public function testEmptyPasscodeIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => '']),
		));
	}

	public function testNonStringPasscodeIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->get('/preview'),
			$this->page('preview', ['passcode' => 12345]),
		));
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

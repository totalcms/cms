<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\AbSplit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Bundled\AbSplit\AbSplitMiddleware;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Twig\Service\TwigEngine;

// Bundled extensions live outside the PSR-4 autoload path; require manually.
require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/ab-split/AbSplitMiddleware.php';

/**
 * Test subclass that captures emitted Set-Cookie strings instead of calling
 * PHP's global header() function. Lets us assert on variant-A cookie
 * emission without relying on output buffering or xdebug_get_headers().
 */
class CapturingAbSplitMiddleware extends AbSplitMiddleware
{
	/** @var list<string> */
	public array $emittedCookies = [];

	protected function emitCookie(string $cookie): void
	{
		$this->emittedCookies[] = $cookie;
	}
}

final class AbSplitMiddlewareTest extends TestCase
{
	private TwigEngine&MockObject $twig;
	private CapturingAbSplitMiddleware $middleware;
	private Psr17Factory $psr17;

	protected function setUp(): void
	{
		$this->twig       = $this->createMock(TwigEngine::class);
		$this->middleware = new CapturingAbSplitMiddleware($this->twig);
		$this->psr17      = new Psr17Factory();
	}

	// --- no-op cases ---

	public function testNoAbTemplateConfiguredIsNoOp(): void
	{
		$this->twig->expects($this->never())->method('render');

		$this->assertNull($this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/contact'),
			$this->page('contact', []),
		));
	}

	public function testEmptyAbTemplateStringIsNoOp(): void
	{
		$this->twig->expects($this->never())->method('render');

		$this->assertNull($this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/contact'),
			$this->page('contact', ['abTemplate' => '']),
		));
	}

	public function testNonStringAbTemplateIsNoOp(): void
	{
		$this->twig->expects($this->never())->method('render');

		$this->assertNull($this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/contact'),
			$this->page('contact', ['abTemplate' => 42]),
		));
	}

	// --- bucket selection ---

	public function testExistingCookieAVariantSkipsRender(): void
	{
		$this->twig->expects($this->never())->method('render');

		$request = $this->psr17->createServerRequest('GET', '/contact')
			->withCookieParams(['tcms_ab_contact' => 'a']);

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('contact', ['abTemplate' => 'pages/contact-b.twig']),
		));
	}

	public function testVariantAEmitsCookieSoSubsequentVisitsStick(): void
	{
		// Without the cookie emit, variant-A visitors would re-bucket on
		// every visit until they happen to land on B — broken stickiness.
		$this->twig->expects($this->never())->method('render');

		$result = $this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/contact')
				->withCookieParams(['tcms_ab_contact' => 'a']),
			$this->page('contact', ['abTemplate' => 'pages/contact-b.twig']),
		);

		$this->assertNull($result);
		$this->assertCount(1, $this->middleware->emittedCookies);
		$this->assertStringStartsWith('tcms_ab_contact=a;', $this->middleware->emittedCookies[0]);
		$this->assertStringContainsString('Path=/', $this->middleware->emittedCookies[0]);
		$this->assertStringContainsString('SameSite=Lax', $this->middleware->emittedCookies[0]);
	}

	public function testFreshVisitorBucketedToAGetsCookieEmitted(): void
	{
		// 0% B = always A. Fresh visitor (no cookie). Cookie must still be
		// set so the next visit reads it instead of re-bucketing.
		$this->twig->expects($this->never())->method('render');

		$result = $this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/contact'),
			$this->page('contact', [
				'abTemplate' => 'pages/contact-b.twig',
				'abPercent'  => 0,
			]),
		);

		$this->assertNull($result);
		$this->assertCount(1, $this->middleware->emittedCookies);
		$this->assertStringStartsWith('tcms_ab_contact=a;', $this->middleware->emittedCookies[0]);
	}

	public function testVariantBDoesNotEmitCookieViaHeaderFunction(): void
	{
		// Variant B builds its own Response and attaches Set-Cookie there.
		// emitCookie() is the variant-A escape hatch only.
		$this->twig->method('render')->willReturn('<h1>B</h1>');

		$this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/contact')
				->withCookieParams(['tcms_ab_contact' => 'b']),
			$this->page('contact', ['abTemplate' => 'pages/contact-b.twig']),
		);

		$this->assertSame([], $this->middleware->emittedCookies);
	}

	public function testExistingCookieBVariantRendersAlternate(): void
	{
		$this->twig->expects($this->once())
			->method('render')
			->with('pages/contact-b.twig', $this->anything())
			->willReturn('<h1>Variant B</h1>');

		$request = $this->psr17->createServerRequest('GET', '/contact')
			->withCookieParams(['tcms_ab_contact' => 'b']);

		$response = $this->middleware->handle(
			$request,
			$this->page('contact', ['abTemplate' => 'pages/contact-b.twig']),
		);

		$this->assertNotNull($response);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('<h1>Variant B</h1>', (string)$response->getBody());
		$this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
	}

	public function testZeroPercentNeverPicksB(): void
	{
		$this->twig->expects($this->never())->method('render');

		// Run many times to be confident — random_int won't pick B with weight 0.
		for ($i = 0; $i < 50; $i++) {
			$result = $this->middleware->handle(
				$this->psr17->createServerRequest('GET', '/contact'),
				$this->page('contact', ['abTemplate' => 'pages/contact-b.twig', 'abPercent' => 0]),
			);
			$this->assertNull($result, 'Expected variant A on every call when abPercent=0');
		}
	}

	public function testHundredPercentAlwaysPicksB(): void
	{
		$this->twig->method('render')->willReturn('<h1>B</h1>');

		for ($i = 0; $i < 50; $i++) {
			$result = $this->middleware->handle(
				$this->psr17->createServerRequest('GET', '/contact'),
				$this->page('contact', ['abTemplate' => 'pages/contact-b.twig', 'abPercent' => 100]),
			);
			$this->assertNotNull($result, 'Expected variant B on every call when abPercent=100');
		}
	}

	public function testInvalidPercentFallsBackToDefault(): void
	{
		// `'lots'` is not numeric — middleware should fall back to 50, which
		// means the call still runs (with non-deterministic bucketing). Just
		// verify it doesn't crash and returns either null or a valid response.
		$result = $this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/contact'),
			$this->page('contact', ['abTemplate' => 'pages/contact-b.twig', 'abPercent' => 'lots']),
		);

		$this->assertTrue($result === null || $result->getStatusCode() === 200);
	}

	// --- response shape ---

	public function testVariantBResponseSetsCookie(): void
	{
		$this->twig->method('render')->willReturn('body');

		$response = $this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/contact')
				->withCookieParams(['tcms_ab_contact' => 'b']),
			$this->page('contact', ['abTemplate' => 'pages/contact-b.twig']),
		);

		$this->assertNotNull($response);
		$cookie = $response->getHeaderLine('Set-Cookie');
		$this->assertStringStartsWith('tcms_ab_contact=b;', $cookie);
		$this->assertStringContainsString('Path=/', $cookie);
		$this->assertStringContainsString('SameSite=Lax', $cookie);
	}

	public function testRendersWithPageContextAndQueryParams(): void
	{
		$this->twig->expects($this->once())
			->method('render')
			->with('pages/contact-b.twig', $this->callback(fn (array $data): bool => ($data['page']['id'] ?? '') === 'contact'
					&& ($data['page']['title'] ?? '') === 'Contact'
					&& ($data['params']['ref'] ?? '') === 'email'))
			->willReturn('rendered');

		$request = $this->psr17->createServerRequest('GET', '/contact?ref=email')
			->withCookieParams(['tcms_ab_contact' => 'b']);

		$this->middleware->handle($request, $this->page('contact', [
			'abTemplate' => 'pages/contact-b.twig',
		], ['title' => 'Contact']));
	}

	// --- failure isolation ---

	public function testTwigRenderFailureFallsThroughToNormalPage(): void
	{
		// Twig throws → middleware should NOT 500 the page; it falls through
		// to the normal render. A/B tests breaking should never break the
		// live page.
		$this->twig->method('render')->willThrowException(new \RuntimeException('bad template'));

		$request = $this->psr17->createServerRequest('GET', '/contact')
			->withCookieParams(['tcms_ab_contact' => 'b']);

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('contact', ['abTemplate' => 'pages/missing.twig']),
		));
	}

	// --- bucket isolation per page ---

	public function testCookieIsKeyedPerPageId(): void
	{
		// Same cookie name format keyed by page id — different pages can put
		// the same visitor in different buckets.
		$this->twig->method('render')->willReturn('B');

		$request = $this->psr17->createServerRequest('GET', '/about')
			->withCookieParams(['tcms_ab_contact' => 'b']); // wrong page

		// The `about` page has no matching cookie — the visitor will be
		// re-bucketed for THIS page (probability-based). With abPercent=100
		// to make it deterministic for the assertion:
		$response = $this->middleware->handle($request, $this->page('about', [
			'abTemplate' => 'pages/about-b.twig',
			'abPercent'  => 100,
		]));

		$this->assertNotNull($response);
		$this->assertStringStartsWith('tcms_ab_about=b;', $response->getHeaderLine('Set-Cookie'));
	}

	/**
	 * @param array<string,mixed> $abData     keys for the page's data blob (abTemplate, abPercent)
	 * @param array<string,mixed> $extraPage extra top-level page fields (title, etc.)
	 */
	private function page(string $id, array $abData, array $extraPage = []): PageData
	{
		return new PageData(array_merge(['id' => $id, 'data' => $abData], $extraPage));
	}
}

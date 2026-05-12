<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\GeoRedirect;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TotalCMS\Bundled\GeoRedirect\GeoRedirectMiddleware;
use TotalCMS\Domain\Builder\Data\PageData;

// Bundled extensions live outside the PSR-4 autoload path; require manually.
require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/geo-redirect/GeoRedirectMiddleware.php';

final class GeoRedirectMiddlewareTest extends TestCase
{
	private GeoRedirectMiddleware $middleware;
	private Psr17Factory $psr17;

	protected function setUp(): void
	{
		$this->middleware = new GeoRedirectMiddleware();
		$this->psr17      = new Psr17Factory();
	}

	// --- no-op cases ---

	public function testNoConfigIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->requestWithCountry('US'),
			$this->page('home', []),
		));
	}

	public function testEmptyRulesIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->requestWithCountry('US'),
			$this->page('home', ['geoRedirects' => []]),
		));
	}

	public function testNonArrayRulesIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->requestWithCountry('US'),
			$this->page('home', ['geoRedirects' => 'not an array']),
		));
	}

	public function testNoCountryHeaderIsNoOp(): void
	{
		// Local dev, no CDN — none of the known headers are set.
		$request = $this->psr17->createServerRequest('GET', '/about');

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('about', ['geoRedirects' => ['DE' => '/de/about']]),
		));
	}

	public function testCountryNotInRulesAndNoWildcardIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->requestWithCountry('US'),
			$this->page('about', ['geoRedirects' => ['DE' => '/de/about']]),
		));
	}

	public function testEmptyTargetIsNoOp(): void
	{
		// Admin-typo defense — empty target string shouldn't crash, just skip.
		$this->assertNull($this->middleware->handle(
			$this->requestWithCountry('DE'),
			$this->page('about', ['geoRedirects' => ['DE' => '']]),
		));
	}

	// --- redirect cases ---

	public function testCountryMatchRedirects(): void
	{
		$response = $this->middleware->handle(
			$this->requestWithCountry('DE'),
			$this->page('about', ['geoRedirects' => ['DE' => '/de/about']]),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('/de/about', $response->getHeaderLine('Location'));
	}

	public function testWildcardMatchesUnlistedCountry(): void
	{
		// Compliance pattern: send everyone except US to /eu.
		$response = $this->middleware->handle(
			$this->requestWithCountry('FR'),
			$this->page('about', ['geoRedirects' => [
				'US' => '/about',
				'*'  => '/eu/about',
			]]),
		);

		$this->assertNotNull($response);
		$this->assertSame('/eu/about', $response->getHeaderLine('Location'));
	}

	public function testExplicitCountryWinsOverWildcard(): void
	{
		$response = $this->middleware->handle(
			$this->requestWithCountry('DE'),
			$this->page('about', ['geoRedirects' => [
				'DE' => '/de/about',
				'*'  => '/eu/about',
			]]),
		);

		$this->assertNotNull($response);
		$this->assertSame('/de/about', $response->getHeaderLine('Location'));
	}

	public function testCountryHeaderLookupIsCaseInsensitive(): void
	{
		// CDNs typically send uppercase but we tolerate lowercase too.
		$response = $this->middleware->handle(
			$this->requestWithCountry('de'),
			$this->page('about', ['geoRedirects' => ['DE' => '/de/about']]),
		);

		$this->assertNotNull($response);
		$this->assertSame('/de/about', $response->getHeaderLine('Location'));
	}

	public function testRuleKeysAreNormalizedToUppercase(): void
	{
		// Admin can put `de` in the JSON, we treat it the same as DE.
		$response = $this->middleware->handle(
			$this->requestWithCountry('DE'),
			$this->page('about', ['geoRedirects' => ['de' => '/de/about']]),
		);

		$this->assertNotNull($response);
		$this->assertSame('/de/about', $response->getHeaderLine('Location'));
	}

	// --- header priority ---

	public function testCloudflareHeaderHasHighestPriority(): void
	{
		// All three set — CF-IPCountry wins.
		$request = $this->psr17->createServerRequest('GET', '/about')
			->withHeader('CF-IPCountry', 'DE')
			->withHeader('X-Country-Code', 'FR')
			->withHeader('X-Vercel-IP-Country', 'JP');

		$response = $this->middleware->handle(
			$request,
			$this->page('about', ['geoRedirects' => [
				'DE' => '/de',
				'FR' => '/fr',
				'JP' => '/jp',
			]]),
		);

		$this->assertNotNull($response);
		$this->assertSame('/de', $response->getHeaderLine('Location'));
	}

	public function testFallsThroughToSecondaryHeader(): void
	{
		$request = $this->psr17->createServerRequest('GET', '/about')
			->withHeader('X-Country-Code', 'FR');

		$response = $this->middleware->handle(
			$request,
			$this->page('about', ['geoRedirects' => ['FR' => '/fr']]),
		);

		$this->assertNotNull($response);
		$this->assertSame('/fr', $response->getHeaderLine('Location'));
	}

	public function testEmptyHeaderValueFallsThrough(): void
	{
		// CF-IPCountry sometimes returns 'XX' or empty for Tor / VPN traffic.
		// Empty should fall through to the next header.
		$request = $this->psr17->createServerRequest('GET', '/about')
			->withHeader('CF-IPCountry', '')
			->withHeader('X-Country-Code', 'JP');

		$response = $this->middleware->handle(
			$request,
			$this->page('about', ['geoRedirects' => ['JP' => '/jp']]),
		);

		$this->assertNotNull($response);
		$this->assertSame('/jp', $response->getHeaderLine('Location'));
	}

	// --- loop prevention ---

	public function testNoRedirectWhenAlreadyOnTargetPath(): void
	{
		// Visitor lands on /de/about (the redirect target). We shouldn't
		// 302 them to where they already are — that's an infinite loop.
		$request = $this->psr17->createServerRequest('GET', '/de/about')
			->withHeader('CF-IPCountry', 'DE');

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('de-about', ['geoRedirects' => ['DE' => '/de/about']]),
		));
	}

	public function testTrailingSlashesIgnoredInLoopGuard(): void
	{
		$request = $this->psr17->createServerRequest('GET', '/de/about/')
			->withHeader('CF-IPCountry', 'DE');

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('de-about', ['geoRedirects' => ['DE' => '/de/about']]),
		));
	}

	public function testQueryStringDoesNotConfusedLoopGuard(): void
	{
		// Visitor on /de/about?ref=email shouldn't be re-redirected to /de/about.
		$request = $this->psr17->createServerRequest('GET', '/de/about?ref=email')
			->withHeader('CF-IPCountry', 'DE');

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('de-about', ['geoRedirects' => ['DE' => '/de/about']]),
		));
	}

	public function testAbsoluteUrlTargetLoopGuardComparesPathOnly(): void
	{
		// Customer used a full URL as target — loop guard should still fire
		// when the visitor is on the same path.
		$request = $this->psr17->createServerRequest('GET', '/de/about')
			->withHeader('CF-IPCountry', 'DE');

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('de-about', ['geoRedirects' => ['DE' => 'https://example.com/de/about']]),
		));
	}

	// --- response shape ---

	public function testResponseSetsVaryHeaderForUpstreamCaches(): void
	{
		$response = $this->middleware->handle(
			$this->requestWithCountry('DE'),
			$this->page('about', ['geoRedirects' => ['DE' => '/de/about']]),
		);

		$this->assertNotNull($response);
		$vary = $response->getHeaderLine('Vary');
		// Should advertise that response varies by the country headers we read.
		$this->assertStringContainsString('CF-IPCountry', $vary);
	}

	// --- helpers ---

	/** @param array<string,mixed> $data */
	private function page(string $id, array $data): PageData
	{
		return new PageData(['id' => $id, 'data' => $data]);
	}

	private function requestWithCountry(string $country): \Psr\Http\Message\ServerRequestInterface
	{
		return $this->psr17->createServerRequest('GET', '/about')
			->withHeader('CF-IPCountry', $country);
	}
}

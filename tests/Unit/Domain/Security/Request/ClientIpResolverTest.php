<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Security\Request;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Domain\Security\Request\CloudflareIpRanges;

/**
 * One place decides which address a request came from, for every rate limiter,
 * the webhook throttle and the OAuth registration log. Six copies of this logic
 * used to trust `CF-Connecting-IP` and `X-Forwarded-For` unconditionally, and a
 * client can set both — so on an install reachable directly from the internet,
 * a caller could hand itself a new identity per request and no per-IP limit
 * applied to it at all.
 */
final class ClientIpResolverTest extends TestCase
{
	/** @param array<string,string> $headers */
	private function request(string $remoteAddr, array $headers = []): ServerRequestInterface
	{
		$request = (new Psr17Factory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => $remoteAddr]);

		foreach ($headers as $name => $value) {
			$request = $request->withHeader($name, $value);
		}

		return $request;
	}

	private function resolver(string $trust = ClientIpResolver::TRUST_AUTO): ClientIpResolver
	{
		return \testClientIpResolver($trust);
	}

	// ── auto: the shipped default ────────────────────────────────────────────

	public function testUsesTheConnectingAddressWhenNoProxyHeadersArePresent(): void
	{
		$this->assertSame('203.0.113.5', $this->resolver()->resolve($this->request('203.0.113.5')));
	}

	public function testIgnoresProxyHeadersFromAnUnrecognisedPublicPeer(): void
	{
		// 203.0.113.5 is neither private nor a Cloudflare edge, so nothing
		// forwarded this request and the headers are the caller's own claim
		// about itself. This is the spoof the whole class exists to stop.
		$resolved = $this->resolver()->resolve($this->request('203.0.113.5', [
			'CF-Connecting-IP' => '1.1.1.1',
			'X-Forwarded-For'  => '2.2.2.2',
		]));

		$this->assertSame('203.0.113.5', $resolved);
	}

	/** @dataProvider privatePeers */
	public function testTrustsProxyHeadersFromAPrivateOrLoopbackPeer(string $peer): void
	{
		// A reverse proxy on the same host or LAN — nginx, Apache, a container
		// network. The client address it forwards is the only real one we have.
		$resolved = $this->resolver()->resolve($this->request($peer, ['CF-Connecting-IP' => '198.51.100.9']));

		$this->assertSame('198.51.100.9', $resolved);
	}

	/** @return array<string,array{string}> */
	public static function privatePeers(): array
	{
		return [
			'loopback'            => ['127.0.0.1'],
			'RFC 1918 ten'        => ['10.0.0.1'],
			'RFC 1918 172'        => ['172.16.0.1'],
			'RFC 1918 192.168'    => ['192.168.1.1'],
			'link local'          => ['169.254.1.1'],
			'IPv6 loopback'       => ['::1'],
			'IPv6 unique local'   => ['fd00::1'],
		];
	}

	// ── Reading the headers ──────────────────────────────────────────────────

	public function testPrefersCloudflaresHeaderOverTheForwardedChain(): void
	{
		$resolved = $this->resolver()->resolve($this->request('10.0.0.1', [
			'CF-Connecting-IP' => '198.51.100.9',
			'X-Forwarded-For'  => '203.0.113.7',
		]));

		$this->assertSame('198.51.100.9', $resolved);
	}

	public function testTakesTheOriginalClientFromAForwardedChain(): void
	{
		// "client, proxy1, proxy2" — the client is the first hop, and the
		// surrounding whitespace must not become part of the address.
		$resolved = $this->resolver()->resolve($this->request('10.0.0.1', [
			'X-Forwarded-For' => ' 198.51.100.9 , 70.41.3.18 , 10.0.0.1 ',
		]));

		$this->assertSame('198.51.100.9', $resolved);
	}

	public function testFallsBackToThePeerWhenTheHeaderIsNotAnAddress(): void
	{
		// A header carrying junk must not become a cache key in its own right,
		// which would hand the sender a private bucket per junk value.
		$resolved = $this->resolver()->resolve($this->request('10.0.0.1', [
			'CF-Connecting-IP' => 'not-an-ip',
		]));

		$this->assertSame('10.0.0.1', $resolved);
	}

	public function testSkipsAJunkCloudflareHeaderInFavourOfAValidChain(): void
	{
		$resolved = $this->resolver()->resolve($this->request('10.0.0.1', [
			'CF-Connecting-IP' => '',
			'X-Forwarded-For'  => '198.51.100.9',
		]));

		$this->assertSame('198.51.100.9', $resolved);
	}

	public function testAcceptsAnIpv6ClientFromAProxy(): void
	{
		$resolved = $this->resolver()->resolve($this->request('10.0.0.1', [
			'X-Forwarded-For' => '2001:db8::1, 10.0.0.1',
		]));

		$this->assertSame('2001:db8::1', $resolved);
	}

	public function testReportsAPlaceholderWhenThereIsNoPeerAtAll(): void
	{
		// Under the CLI, or a server that did not populate REMOTE_ADDR.
		$request = (new Psr17Factory())->createServerRequest('GET', '/');

		$this->assertSame('0.0.0.0', $this->resolver()->resolve($request));
	}

	public function testDoesNotTrustHeadersJustBecauseThePeerIsMissing(): void
	{
		$resolved = $this->resolver()->resolve(
			(new Psr17Factory())->createServerRequest('GET', '/')->withHeader('CF-Connecting-IP', '1.1.1.1')
		);

		$this->assertSame('0.0.0.0', $resolved);
	}

	// ── always / never ───────────────────────────────────────────────────────

	public function testAlwaysTrustsHeadersWhenTheOperatorOptsIn(): void
	{
		$resolved = $this->resolver(ClientIpResolver::TRUST_ALWAYS)
			->resolve($this->request('203.0.113.5', ['CF-Connecting-IP' => '198.51.100.9']));

		$this->assertSame('198.51.100.9', $resolved);
	}

	public function testAlwaysStillFallsBackWhenNoHeaderIsPresent(): void
	{
		$this->assertSame(
			'203.0.113.5',
			$this->resolver(ClientIpResolver::TRUST_ALWAYS)->resolve($this->request('203.0.113.5'))
		);
	}

	public function testNeverIgnoresHeadersEvenFromAPrivatePeer(): void
	{
		$resolved = $this->resolver(ClientIpResolver::TRUST_NEVER)
			->resolve($this->request('10.0.0.1', ['CF-Connecting-IP' => '198.51.100.9']));

		$this->assertSame('10.0.0.1', $resolved);
	}

	public function testAnUnrecognisedSettingFallsBackToAutoRatherThanTrusting(): void
	{
		// A typo in tcms.php must fail towards the safe behaviour, not the
		// permissive one.
		$resolved = $this->resolver('yes-please')
			->resolve($this->request('203.0.113.5', ['CF-Connecting-IP' => '1.1.1.1']));

		$this->assertSame('203.0.113.5', $resolved);
	}

	// ── Telling the operator what is happening ───────────────────────────────

	public function testFlagsProxyHeadersThatAreBeingIgnored(): void
	{
		$resolver = $this->resolver();

		$this->assertTrue($resolver->hasIgnoredProxyHeaders(
			$this->request('203.0.113.5', ['CF-Connecting-IP' => '1.1.1.1'])
		));
		$this->assertFalse($resolver->hasIgnoredProxyHeaders(
			$this->request('10.0.0.1', ['CF-Connecting-IP' => '1.1.1.1'])
		));
		$this->assertFalse($resolver->hasIgnoredProxyHeaders($this->request('203.0.113.5')));
	}

	public function testTheDiagnosticNamesTheFixWhenHeadersAreBeingIgnored(): void
	{
		// The symptom on a misconfigured Cloudflare install is "rate limits are
		// firing on real visitors", which points nowhere near this setting. The
		// server-info panel has to close that gap itself.
		$summary = $this->resolver()->diagnosticSummary([
			'REMOTE_ADDR'           => '203.0.113.5',
			'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
		]);

		$this->assertStringContainsString('IGNORING', $summary);
		$this->assertStringContainsString('203.0.113.5', $summary);
		// It has to name the way out. Cloudflare is handled by the bundled
		// ranges, so what is left here is another CDN or proxy, and the answer
		// for that is the setting.
		$this->assertStringContainsString('trustProxyHeaders', $summary);
		$this->assertStringContainsString('cannot be reached directly', $summary);
	}

	public function testTheDiagnosticIsQuietWhenThingsAreWiredCorrectly(): void
	{
		$proxied = $this->resolver()->diagnosticSummary([
			'REMOTE_ADDR'           => '10.0.0.1',
			'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
		]);
		$this->assertStringContainsString('trusting', $proxied);
		$this->assertStringNotContainsString('IGNORING', $proxied);

		$direct = $this->resolver()->diagnosticSummary(['REMOTE_ADDR' => '203.0.113.5']);
		$this->assertStringContainsString('no proxy headers', $direct);

		$optedIn = $this->resolver(ClientIpResolver::TRUST_ALWAYS)->diagnosticSummary([]);
		$this->assertStringContainsString('always', $optedIn);

		$off = $this->resolver(ClientIpResolver::TRUST_NEVER)->diagnosticSummary(['REMOTE_ADDR' => '10.0.0.1']);
		$this->assertStringContainsString('never', $off);
	}

	public function testTheDiagnosticSeesEveryHeaderTheResolverItselfHonours(): void
	{
		// diagnosticSummary() reads $_SERVER, where the headers appear under
		// their CGI names, so it derives those from the same list resolve()
		// uses. This fails if a header is ever added to one and not the other,
		// which would leave the panel reporting "no proxy headers" on an
		// install that is quietly ignoring one.
		$headers = (new \ReflectionClassConstant(ClientIpResolver::class, 'FORWARD_HEADERS'))->getValue();

		foreach ($headers as $header) {
			$cgiName = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
			$summary = $this->resolver()->diagnosticSummary([
				'REMOTE_ADDR' => '203.0.113.5',
				$cgiName      => '1.1.1.1',
			]);

			$this->assertStringContainsString('IGNORING', $summary, "{$header} not detected by the diagnostic");
		}
	}

	// ── Cloudflare ───────────────────────────────────────────────────────────

	public function testTrustsCloudflaresHeaderFromACloudflareEdge(): void
	{
		// The reason 'auto' is a usable default for the many installs behind
		// Cloudflare: the edge connects from a public address, so the
		// private-peer rule alone would ignore it and collapse every visitor
		// into one rate-limit bucket.
		$resolved = $this->resolver()->resolve($this->request('172.68.10.1', [
			'CF-Connecting-IP' => '198.51.100.9',
		]));

		$this->assertSame('198.51.100.9', $resolved);
	}

	public function testStillRefusesAForgedCloudflareHeaderFromElsewhere(): void
	{
		// Knowing Cloudflare's ranges must not become "believe anyone who sends
		// Cloudflare's header".
		$resolved = $this->resolver()->resolve($this->request('203.0.113.5', [
			'CF-Connecting-IP' => '198.51.100.9',
		]));

		$this->assertSame('203.0.113.5', $resolved);
	}

	public function testAPrivateAddressIsNotMistakenForACloudflareEdge(): void
	{
		// Cloudflare's 172.64.0.0/13 looks like RFC 1918's 172.16.0.0/12 and is
		// not part of it. Both are trusted here, but for different reasons, and
		// the diagnostic has to say which.
		$this->assertStringContainsString(
			'Cloudflare',
			$this->resolver()->diagnosticSummary([
				'REMOTE_ADDR'           => '172.68.10.1',
				'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
			]),
		);
		$this->assertStringNotContainsString(
			'Cloudflare',
			$this->resolver()->diagnosticSummary([
				'REMOTE_ADDR'           => '172.16.0.1',
				'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
			]),
		);
	}

	public function testReportsWhenTheBundledCloudflareRangesLookStale(): void
	{
		// The failure this exists to make visible: Cloudflare adds an address
		// range, this release does not know it yet, and every visitor silently
		// starts sharing a bucket. CF-Ray is what separates that from "there is
		// no proxy" — it is stamped by Cloudflare on every forwarded request.
		$summary = $this->resolver()->diagnosticSummary([
			'REMOTE_ADDR'           => '203.0.113.5',
			'HTTP_CF_RAY'           => '8a1b2c3d4e5f6789-LHR',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.9',
		]);

		$this->assertStringContainsString('CF-Ray', $summary);
		$this->assertStringContainsString('NOT in the Cloudflare ranges', $summary);
		$this->assertStringContainsString(CloudflareIpRanges::LAST_VERIFIED, $summary);
	}

	public function testDoesNotCryStaleWhenTheRangesAreWorking(): void
	{
		$summary = $this->resolver()->diagnosticSummary([
			'REMOTE_ADDR' => '172.68.10.1',
			'HTTP_CF_RAY' => '8a1b2c3d4e5f6789-LHR',
		]);

		$this->assertStringNotContainsString('NOT in the Cloudflare ranges', $summary);
	}

	public function testCfRayNeverGrantsTrustOnItsOwn(): void
	{
		// The header is forgeable, so it is only ever a hint for the operator.
		// Believing it would reopen the spoof by a new name.
		$resolved = $this->resolver()->resolve($this->request('203.0.113.5', [
			'CF-Ray'           => '8a1b2c3d4e5f6789-LHR',
			'CF-Connecting-IP' => '198.51.100.9',
		]));

		$this->assertSame('203.0.113.5', $resolved);
	}
}

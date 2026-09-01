<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Security\Request;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Security\Request\CidrMatcher;
use TotalCMS\Domain\Security\Request\CloudflareIpRanges;

/**
 * This decides whether a request really came from Cloudflare, which decides
 * whether `CF-Connecting-IP` is believed. Too loose and the spoof this whole
 * area exists to stop comes back; too tight and every Cloudflare visitor shares
 * one rate-limit bucket. The boundaries are the tests worth having.
 */
final class CidrMatcherTest extends TestCase
{
	private CidrMatcher $matcher;

	protected function setUp(): void
	{
		$this->matcher = new CidrMatcher();
	}

	/** @dataProvider ipv4Boundaries */
	public function testMatchesIpv4RangesInclusiveOfTheirEdges(string $ip, bool $expected): void
	{
		$this->assertSame($expected, $this->matcher->matches($ip, ['173.245.48.0/20']));
	}

	/** @return array<string,array{string,bool}> */
	public static function ipv4Boundaries(): array
	{
		return [
			'first address in range' => ['173.245.48.0', true],
			'inside range'           => ['173.245.55.17', true],
			'last address in range'  => ['173.245.63.255', true],
			'one past the end'       => ['173.245.64.0', false],
			'one before the start'   => ['173.245.47.255', false],
			'unrelated address'      => ['203.0.113.5', false],
		];
	}

	public function testHandlesAPrefixThatStopsMidByte(): void
	{
		// /22 splits a byte, which is where a whole-byte comparison would be
		// wrong in both directions.
		$this->assertTrue($this->matcher->matches('103.21.244.0', ['103.21.244.0/22']));
		$this->assertTrue($this->matcher->matches('103.21.247.255', ['103.21.244.0/22']));
		$this->assertFalse($this->matcher->matches('103.21.248.0', ['103.21.244.0/22']));
		$this->assertFalse($this->matcher->matches('103.21.243.255', ['103.21.244.0/22']));
	}

	public function testDoesNotConfuseAddressesThatShareATextualPrefix(): void
	{
		// '10.0.0.1' is a prefix of the string '10.0.0.10'. Comparing text
		// rather than packed bytes gets this wrong.
		$this->assertFalse($this->matcher->matches('10.0.0.10', ['10.0.0.1/32']));
		$this->assertTrue($this->matcher->matches('10.0.0.1', ['10.0.0.1/32']));
	}

	public function testMatchesIpv6RangesRegardlessOfHowTheyAreWritten(): void
	{
		$this->assertTrue($this->matcher->matches('2400:cb00::1', ['2400:cb00::/32']));
		$this->assertTrue($this->matcher->matches('2400:cb00:0000:0000:0000:0000:0000:0001', ['2400:cb00::/32']));
		$this->assertFalse($this->matcher->matches('2001:db8::1', ['2400:cb00::/32']));
	}

	public function testHandlesAnIpv6PrefixThatStopsMidByte(): void
	{
		// Cloudflare publishes a /29, which is not byte-aligned.
		$this->assertTrue($this->matcher->matches('2a06:98c0::1', ['2a06:98c0::/29']));
		$this->assertFalse($this->matcher->matches('2a06:98d0::1', ['2a06:98c0::/29']));
	}

	public function testNeverMatchesAcrossAddressFamilies(): void
	{
		// An IPv4 address is not inside an IPv6 range, and the packed forms are
		// different lengths — comparing them would read past the shorter one.
		$this->assertFalse($this->matcher->matches('173.245.48.1', ['2400:cb00::/32']));
		$this->assertFalse($this->matcher->matches('2400:cb00::1', ['173.245.48.0/20']));
	}

	public function testRejectsMalformedInputInsteadOfMatchingIt(): void
	{
		// A malformed range must never widen the match — that would be a
		// silent grant of trust.
		$this->assertFalse($this->matcher->matches('not-an-ip', ['173.245.48.0/20']));
		$this->assertFalse($this->matcher->matches('173.245.48.1', ['not-a-range']));
		$this->assertFalse($this->matcher->matches('173.245.48.1', ['173.245.48.0']));
		$this->assertFalse($this->matcher->matches('173.245.48.1', ['173.245.48.0/x']));
		$this->assertFalse($this->matcher->matches('173.245.48.1', ['173.245.48.0/33']));
		$this->assertFalse($this->matcher->matches('173.245.48.1', []));
	}

	public function testAZeroPrefixMatchesEverythingOfThatFamily(): void
	{
		// Not used by the bundled ranges, but a config-supplied 0.0.0.0/0 must
		// behave predictably rather than by accident.
		$this->assertTrue($this->matcher->matches('203.0.113.5', ['0.0.0.0/0']));
		$this->assertFalse($this->matcher->matches('2001:db8::1', ['0.0.0.0/0']));
	}

	public function testStopsAtTheFirstMatchingRangeInAList(): void
	{
		$this->assertTrue($this->matcher->matches('104.16.0.1', ['203.0.113.0/24', '104.16.0.0/13']));
	}

	// ── The bundled Cloudflare list ──────────────────────────────────────────

	public function testTheBundledRangesAreWellFormed(): void
	{
		// These are generated at build time from cloudflare.com, so this guards
		// the generator's output as much as the data: a truncated or mangled
		// list would silently stop matching Cloudflare entirely.
		$this->assertGreaterThanOrEqual(10, count(CloudflareIpRanges::V4));
		$this->assertGreaterThanOrEqual(5, count(CloudflareIpRanges::V6));

		foreach ([...CloudflareIpRanges::V4, ...CloudflareIpRanges::V6] as $cidr) {
			$parts = explode('/', $cidr);
			$this->assertCount(2, $parts, "{$cidr} is not a CIDR");
			$this->assertNotFalse(filter_var($parts[0], FILTER_VALIDATE_IP), "{$cidr} has no valid address");
		}

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', CloudflareIpRanges::LAST_VERIFIED);
	}

	public function testKnownCloudflareAddressesMatchTheBundledRanges(): void
	{
		$all = [...CloudflareIpRanges::V4, ...CloudflareIpRanges::V6];

		foreach (['172.68.10.1', '104.16.0.1', '131.0.72.5', '2400:cb00::1'] as $edge) {
			$this->assertTrue($this->matcher->matches($edge, $all), "{$edge} should be Cloudflare");
		}

		// 172.64.0.0/13 sits just outside RFC 1918's 172.16.0.0/12 — a private
		// address must not be mistaken for a Cloudflare edge, or vice versa.
		foreach (['172.16.0.1', '10.0.0.1', '203.0.113.5', '2001:db8::1'] as $other) {
			$this->assertFalse($this->matcher->matches($other, $all), "{$other} should not be Cloudflare");
		}
	}
}

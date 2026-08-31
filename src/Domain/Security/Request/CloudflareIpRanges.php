<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Request;

/**
 * Cloudflare's published edge ranges.
 *
 * GENERATED FILE — do not edit by hand. Regenerate with
 * `php bin/update-cloudflare-ips.php`, which bin/build.sh runs, so each
 * release ships the list as it stood at build time.
 *
 * ClientIpResolver uses these to decide whether `CF-Connecting-IP` came
 * from Cloudflare or from someone claiming to be Cloudflare. A stale list
 * does not fail loudly: it collapses every visitor into one rate-limit
 * bucket. That is why the resolver also watches for `CF-Ray` arriving
 * from an address outside these ranges and reports it in Server Info.
 *
 * Source: https://www.cloudflare.com/ips/
 */
final class CloudflareIpRanges
{
	/** When these ranges were last read from cloudflare.com. */
	public const LAST_VERIFIED = '2026-08-31';

	/** @var list<string> */
	public const V4 = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	];

	/** @var list<string> */
	public const V6 = [
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];
}

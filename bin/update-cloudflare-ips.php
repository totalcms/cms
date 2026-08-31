<?php

declare(strict_types=1);

/**
 * Regenerate the bundled Cloudflare IP ranges from cloudflare.com.
 *
 * Run from bin/build.sh, so a release picks up the current list without anyone
 * remembering to. The ranges decide whether `CF-Connecting-IP` is believed, so
 * a stale list quietly collapses every visitor of a Cloudflare site into one
 * rate-limit bucket — see ClientIpResolver.
 *
 * NEVER fails the build and never writes a short list. If the fetch fails, or
 * returns something that does not look like the published list, the committed
 * ranges are left exactly as they are: shipping yesterday's correct list beats
 * shipping today's empty one.
 */

const SOURCES = [
	'V4' => 'https://www.cloudflare.com/ips-v4',
	'V6' => 'https://www.cloudflare.com/ips-v6',
];

// Sanity bounds. The real lists have been 15 and 7 entries for years; these are
// wide enough to allow real growth and narrow enough to reject a captive portal
// or an error page that happens to contain a slash.
const MIN_ENTRIES = 5;
const MAX_ENTRIES = 200;

const TARGET = __DIR__ . '/../src/Domain/Security/Request/CloudflareIpRanges.php';

/** The date on the ranges already committed, for the "kept the old list" message. */
function currentLastVerified(): string
{
	$existing = is_file(TARGET) ? (string)file_get_contents(TARGET) : '';
	preg_match("/LAST_VERIFIED = '([^']*)'/", $existing, $m);

	return $m[1] ?? 'an unknown date';
}

/** @return list<string>|null */
function fetchRanges(string $url): ?array
{
	$context = stream_context_create(['http' => [
		'timeout'    => 15,
		'user_agent' => 'TotalCMS-build',
	]]);

	$body = @file_get_contents($url, false, $context);
	if (!is_string($body) || trim($body) === '') {
		fwrite(STDERR, "  ! could not fetch {$url}\n");

		return null;
	}

	$ranges = [];
	foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}

		// Every line must be a CIDR. Anything else means we are not looking at
		// the published list — an error page, a redirect, a captive portal —
		// and one bad entry is enough to reject the whole response.
		if (!isValidCidr($line)) {
			fwrite(STDERR, "  ! unexpected content from {$url}: " . substr($line, 0, 40) . "\n");

			return null;
		}

		$ranges[] = $line;
	}

	$count = count($ranges);
	if ($count < MIN_ENTRIES || $count > MAX_ENTRIES) {
		fwrite(STDERR, "  ! {$url} returned {$count} entries, outside the expected range\n");

		return null;
	}

	return $ranges;
}

function isValidCidr(string $cidr): bool
{
	$parts = explode('/', $cidr);
	if (count($parts) !== 2) {
		return false;
	}

	$address = filter_var($parts[0], FILTER_VALIDATE_IP);
	if ($address === false || !ctype_digit($parts[1])) {
		return false;
	}

	$maxBits = str_contains($parts[0], ':') ? 128 : 32;

	return (int)$parts[1] >= 0 && (int)$parts[1] <= $maxBits;
}

/** @param array<string,list<string>> $sets */
function render(array $sets, string $verifiedOn): string
{
	$constants = '';
	foreach ($sets as $name => $ranges) {
		$entries = implode('', array_map(
			static fn (string $r): string => "\t\t'{$r}',\n",
			$ranges,
		));
		$constants .= "\n\t/** @var list<string> */\n\tpublic const {$name} = [\n{$entries}\t];\n";
	}

	return <<<PHP
		<?php

		declare(strict_types=1);

		namespace TotalCMS\\Domain\\Security\\Request;

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
			public const LAST_VERIFIED = '{$verifiedOn}';
		{$constants}}

		PHP;
}

echo "Updating Cloudflare IP ranges...\n";

$sets = [];
foreach (SOURCES as $name => $url) {
	$ranges = fetchRanges($url);
	if ($ranges === null) {
		// Exit 2, distinct from a real failure, so build.sh can tell "could not
		// refresh" from "the script is broken" and shout about the first
		// without failing the release over it.
		fwrite(STDERR, "  ! keeping the ranges committed on " . currentLastVerified() . "\n");
		exit(2);
	}
	printf("  %s: %d ranges\n", $name, count($ranges));
	$sets[$name] = $ranges;
}

$existing = is_file(TARGET) ? (string)file_get_contents(TARGET) : '';
$rendered = render($sets, date('Y-m-d'));

// Compare everything but the date, so an unchanged list does not churn the file
// (and the bundle manifest) on every build.
$strip = static fn (string $php): string => (string)preg_replace(
	"/LAST_VERIFIED = '[^']*'/",
	'',
	$php,
);

if ($existing !== '' && $strip($existing) === $strip($rendered)) {
	echo "  ranges unchanged\n";
	exit(0);
}

file_put_contents(TARGET, $rendered);
echo '  wrote ' . basename(TARGET) . "\n";

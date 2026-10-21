<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * GET a URL that someone else typed — a member's website, a feed, a link
 * pasted into a form — without letting it reach this server's own network.
 *
 * The host must resolve only to public addresses, and the connection is
 * pinned to the address that was checked (no second DNS lookup to rebind
 * to). Redirects are followed by hand, each hop checked the same way.
 * http and https only, no credentials in the URL, a short timeout, and the
 * body is read up to `max_bytes`; the rest is dropped, not an error.
 *
 * Automations get it as `$ctx->publicHttp`.
 */
final readonly class PublicHttpClient
{
	private const USER_AGENT = 'TotalCMS';

	/** @var \Closure(string): list<string> host => its addresses */
	private \Closure $resolver;

	/** @var \Closure(string, string, int, string, array<string,mixed>): array{status: int, location: string, body: string} */
	private \Closure $transport;

	/**
	 * Both seams default to the real thing; tests pass their own.
	 *
	 * @param (\Closure(string): list<string>)|null $resolver
	 * @param (\Closure(string, string, int, string, array<string,mixed>): array{status: int, location: string, body: string})|null $transport
	 */
	public function __construct(?\Closure $resolver = null, ?\Closure $transport = null)
	{
		$this->resolver  = $resolver ?? self::resolve(...);
		$this->transport = $transport ?? self::curl(...);
	}

	/**
	 * @param array{timeout?: int, max_bytes?: int, max_redirects?: int, user_agent?: string, headers?: array<string,string>} $options
	 *   Defaults: 10 second timeout, 2 MB, 3 redirects.
	 *
	 * @throws \RuntimeException when the URL, a redirect or its address is refused, or the request fails
	 */
	public function get(string $url, array $options = []): HttpResponse
	{
		$opts = [
			'timeout'    => (int)($options['timeout'] ?? 10),
			'max_bytes'  => (int)($options['max_bytes'] ?? 2_000_000),
			'user_agent' => (string)($options['user_agent'] ?? self::USER_AGENT),
			'headers'    => $options['headers'] ?? [],
		];
		$maxRedirects = (int)($options['max_redirects'] ?? 3);

		for ($hop = 0; $hop <= $maxRedirects; $hop++) {
			[$host, $port] = $this->target($url);
			$ip            = $this->publicAddress($host);
			$reply         = ($this->transport)($url, $host, $port, $ip, $opts);

			if ($reply['status'] >= 300 && $reply['status'] < 400 && $reply['location'] !== '') {
				$url = self::absolute($reply['location'], $url);

				continue;
			}

			return new HttpResponse($reply['status'], $reply['body']);
		}

		throw new \RuntimeException('Too many redirects');
	}

	/**
	 * Whether an address is one a request from this server may reach: not
	 * private, loopback, link-local, reserved or an IPv4-mapped one of those.
	 */
	public static function isPublicIp(string $ip): bool
	{
		if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $mapped) === 1) {
			$ip = $mapped[1];
		}

		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}

	/**
	 * @return array{0: string, 1: int} host (IPv6 without brackets) and port
	 */
	private function target(string $url): array
	{
		$parts  = parse_url($url);
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host   = strtolower(trim((string)($parts['host'] ?? ''), '[]'));

		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new \RuntimeException('Only http and https URLs can be fetched');
		}
		if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
			throw new \RuntimeException('The URL has no host, or carries credentials');
		}

		return [$host, (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
	}

	/**
	 * Every address the host has must be public: one private record is
	 * enough to refuse, so a name cannot split its answers. Returns the one
	 * to connect to, IPv4 first.
	 */
	private function publicAddress(string $host): string
	{
		$ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
		if ($ips === []) {
			throw new \RuntimeException('The host does not resolve: ' . $host);
		}
		foreach ($ips as $ip) {
			if (!self::isPublicIp($ip)) {
				throw new \RuntimeException('The host resolves to a non-public address: ' . $host);
			}
		}
		usort($ips, static fn (string $a, string $b): int => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));

		return $ips[0];
	}

	/**
	 * @return list<string>
	 */
	private static function resolve(string $host): array
	{
		$v4 = gethostbynamel($host) ?: [];
		$v6 = [];
		foreach (QuietCall::run(static fn () => dns_get_record($host, DNS_AAAA)) ?: [] as $record) {
			if (isset($record['ipv6'])) {
				$v6[] = (string)$record['ipv6'];
			}
		}

		return array_values(array_unique([...$v4, ...$v6]));
	}

	/**
	 * One request, no redirects followed, connected to `$ip` only.
	 *
	 * @param array<string,mixed> $opts
	 *
	 * @return array{status: int, location: string, body: string}
	 */
	private static function curl(string $url, string $host, int $port, string $ip, array $opts): array
	{
		$body     = '';
		$maxBytes = (int)$opts['max_bytes'];
		$agent    = (string)$opts['user_agent'];
		$agent    = $agent === '' ? self::USER_AGENT : $agent;
		$headers  = [];
		foreach ((array)$opts['headers'] as $name => $value) {
			$headers[] = $name . ': ' . $value;
		}

		$ch = curl_init($url);
		if ($ch === false) {
			throw new \RuntimeException('Failed to initialize cURL');
		}
		curl_setopt_array($ch, [
			CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => min(5, (int)$opts['timeout']),
			CURLOPT_TIMEOUT        => (int)$opts['timeout'],
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT      => $agent,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_ENCODING       => '',
			// Keep what fits and stop reading; the caller gets the start.
			CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
				$room = $maxBytes > 0 ? $maxBytes - strlen($body) : strlen($chunk);
				$body .= substr($chunk, 0, max(0, $room));

				return $maxBytes > 0 && strlen($body) >= $maxBytes ? 0 : strlen($chunk);
			},
		]);

		curl_exec($ch);
		$status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
		$errno    = curl_errno($ch);
		$error    = curl_error($ch);

		// CURLE_WRITE_ERROR is the size cap stopping the read on purpose.
		if ($status === 0 || ($errno !== 0 && $errno !== CURLE_WRITE_ERROR)) {
			throw new \RuntimeException('HTTP request failed: ' . ($error ?: 'no response'));
		}

		return ['status' => $status, 'location' => $location, 'body' => $body];
	}

	/**
	 * A Location header made absolute against the URL it came from.
	 */
	private static function absolute(string $location, string $base): string
	{
		if (preg_match('{^[a-z][a-z0-9+.-]*:}i', $location) === 1) {
			return $location;
		}
		$parts  = parse_url($base);
		$origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($location, '//')) {
			return ($parts['scheme'] ?? 'https') . ':' . $location;
		}
		if (str_starts_with($location, '/')) {
			return $origin . $location;
		}
		$dir = preg_replace('{/[^/]*$}', '/', (string)($parts['path'] ?? '/')) ?: '/';

		return $origin . $dir . $location;
	}
}

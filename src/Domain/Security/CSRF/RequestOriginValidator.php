<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\CSRF;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Support\Config;

/**
 * Decides whether a request was initiated by this site's own pages, using the
 * browser-set Origin header (falling back to Referer).
 *
 * **Why this is CSRF-grade.** `Origin` is a forbidden header name: page script
 * cannot set or spoof it on a fetch/XHR, the browser stamps it, and it is sent
 * on every state-changing request. A matching Origin therefore proves the same
 * thing a CSRF token proves — the request came from us — without requiring the
 * client to send anything. A non-browser client can forge it freely, but a
 * non-browser client has no victim cookie to ride, which is the only thing CSRF
 * is about.
 *
 * **What it catches that SameSite=Lax doesn't.** SameSite is scoped to the
 * registrable domain, so `evil.example.com` is same-*site* with
 * `www.example.com` and the session cookie is sent. It is a different *origin*,
 * so this check rejects it.
 *
 * **Trusted host candidates.** The comparison is only as good as what we
 * compare against, so the candidates are limited to values a caller cannot
 * nominate for itself:
 *
 *   - `$config->domain` — auto-detected from HTTP_HOST/SERVER_NAME at boot and
 *     overridable by the operator in `config/tcms.php`.
 *   - the request URI host — derived from the Host header, which is also a
 *     forbidden header name and so browser-controlled.
 *
 * `X-Forwarded-Host` is deliberately NOT a candidate. It is an ordinary header
 * that any client may send, so honouring it would let a caller supply both
 * sides of the comparison and declare itself same-origin.
 *
 * Comparison is host-only — scheme and port are ignored — which matches "same
 * domain" and survives TLS-terminating proxies.
 */
final readonly class RequestOriginValidator
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * What the browser says about this request's origin.
	 */
	public function verdict(ServerRequestInterface $request): OriginVerdict
	{
		$candidates = $this->trustedHosts($request);
		if ($candidates === []) {
			return OriginVerdict::Unknown;
		}

		foreach (['Origin', 'Referer'] as $header) {
			$value = $request->getHeaderLine($header);
			if ($value === '') {
				continue;
			}

			// Origin is authoritative; Referer is only the fallback for browsers
			// that omit Origin. The first header present decides — a present but
			// mismatched Origin is a hard no, not a reason to try the next one.
			// `Origin: null` (sandboxed iframes, some redirect chains) parses to
			// no host and so falls through to CrossOrigin, which is correct.
			return in_array($this->hostOf($value), $candidates, true)
				? OriginVerdict::SameOrigin
				: OriginVerdict::CrossOrigin;
		}

		return OriginVerdict::Unknown;
	}

	/**
	 * Whether the request is browser-verified as coming from this site.
	 *
	 * Collapses Unknown to false: callers using this boolean form want proof,
	 * and "no headers to judge by" is not proof.
	 */
	public function isSameOrigin(ServerRequestInterface $request): bool
	{
		return $this->verdict($request) === OriginVerdict::SameOrigin;
	}

	/**
	 * Hosts that count as "us". Both entries are browser-controlled or
	 * operator-controlled; neither can be nominated by the caller.
	 *
	 * @return list<string>
	 */
	private function trustedHosts(ServerRequestInterface $request): array
	{
		$hosts = [];

		foreach ([$this->config->domain, $request->getUri()->getHost()] as $candidate) {
			$host = $this->hostOf('//' . $candidate);
			if ($host !== '' && !in_array($host, $hosts, true)) {
				$hosts[] = $host;
			}
		}

		return $hosts;
	}

	/**
	 * Lowercased host of a URL, with any port and userinfo stripped. Parsing
	 * rather than string-splitting keeps IPv6 literals (`[::1]:8080`) intact.
	 */
	private function hostOf(string $url): string
	{
		$host = parse_url($url, PHP_URL_HOST);

		return is_string($host) ? strtolower($host) : '';
	}
}

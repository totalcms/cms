<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Request;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Support\Config;

/**
 * Works out which address a request actually came from.
 *
 * `CF-Connecting-IP` and `X-Forwarded-For` are set by whatever is in front of
 * the server — but a client can set them too, and nothing about the headers
 * themselves says which happened. Six places used to read them unconditionally,
 * each with its own copy of the same helper, so on an install reachable
 * directly from the internet any caller could mint a fresh identity per request
 * and walk straight past a per-IP rate limit.
 *
 * The only value that cannot be forged is the socket peer, `REMOTE_ADDR`. So
 * the headers are honoured only when the peer is something that plausibly *is*
 * a proxy, which `$config->trustProxyHeaders` decides:
 *
 *   'auto'   (default) trust them when the peer is private or loopback — a
 *            reverse proxy on the same host or LAN, which is the common
 *            deployment and needs no configuration.
 *   'always' trust them unconditionally. For Cloudflare and other proxies that
 *            connect from public addresses, where the operator has confirmed
 *            the origin is not reachable directly.
 *   'never'  ignore them and always use the peer address.
 *
 * Deliberately no bundled Cloudflare range list: it would need refreshing
 * forever, and when it went stale it would fail quietly — every visitor
 * collapsing into one bucket and rate limits firing on legitimate traffic. An
 * operator behind Cloudflare should be firewalling the origin to Cloudflare
 * anyway, and once they have, range checking adds nothing.
 */
readonly class ClientIpResolver
{
	public const TRUST_AUTO   = 'auto';
	public const TRUST_ALWAYS = 'always';
	public const TRUST_NEVER  = 'never';

	/** Used when there is no peer address at all, e.g. under the CLI. */
	public const UNKNOWN = '0.0.0.0';

	/** In precedence order: Cloudflare's header, then the standard chain. */
	private const FORWARD_HEADERS = ['CF-Connecting-IP', 'X-Forwarded-For'];

	public function __construct(private Config $config)
	{
	}

	public function resolve(ServerRequestInterface $request): string
	{
		$peer = $this->peerAddress($request);

		if (!$this->shouldTrustHeaders($peer)) {
			return $peer;
		}

		foreach (self::FORWARD_HEADERS as $header) {
			if (!$request->hasHeader($header)) {
				continue;
			}

			// X-Forwarded-For is a chain, "client, proxy1, proxy2"; the client
			// is the first hop. Cloudflare's header carries a single address,
			// and splitting a single address is harmless.
			$candidate = trim(explode(',', $request->getHeaderLine($header))[0]);

			if ($this->isIpAddress($candidate)) {
				return $candidate;
			}
		}

		return $peer;
	}

	/**
	 * Whether a request is presenting proxy headers that are being ignored,
	 * which is what an install behind Cloudflare looks like while still on
	 * 'auto'. Surfaced as a server-check warning so the symptom — every visitor
	 * sharing one rate-limit bucket — comes with the reason attached.
	 */
	public function hasIgnoredProxyHeaders(ServerRequestInterface $request): bool
	{
		if ($this->shouldTrustHeaders($this->peerAddress($request))) {
			return false;
		}

		foreach (self::FORWARD_HEADERS as $header) {
			if ($request->hasHeader($header)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A one-line description of what this install is currently doing with proxy
	 * headers, for the server-info panel.
	 *
	 * The failure this exists to catch is quiet: an install behind Cloudflare
	 * left on 'auto' ignores the headers, every visitor collapses into the one
	 * edge address, and per-IP rate limits start firing on legitimate traffic.
	 * Nothing about that symptom points at this setting, so the panel says so
	 * outright.
	 *
	 * @param array<string,mixed> $serverParams normally $_SERVER
	 */
	public function diagnosticSummary(array $serverParams): string
	{
		$peer      = (string)($serverParams['REMOTE_ADDR'] ?? '');
		$peer      = $peer === '' ? self::UNKNOWN : $peer;
		$hasHeader = false;

		foreach (self::FORWARD_HEADERS as $header) {
			// Derived from the one list rather than spelled out again: $_SERVER
			// holds the same headers under their CGI names, and two hand-kept
			// copies of the same two strings is how they drift apart.
			if (isset($serverParams['HTTP_' . strtoupper(str_replace('-', '_', $header))])) {
				$hasHeader = true;
				break;
			}
		}

		return match (true) {
			$this->config->trustProxyHeaders === self::TRUST_NEVER  => "never — using the connecting address ({$peer})",
			$this->config->trustProxyHeaders === self::TRUST_ALWAYS => 'always — trusting proxy headers',
			!$hasHeader                                             => "auto — no proxy headers on this request, using {$peer}",
			$this->isPrivateAddress($peer)                          => "auto — trusting proxy headers from {$peer}",
			default                                                 => "auto — IGNORING proxy headers: this request came from {$peer}, which is "
					. 'not a private address. If this server is behind Cloudflare, either configure '
					. "mod_remoteip / nginx real_ip for Cloudflare's ranges (preferred) or set "
					. "trustProxyHeaders to 'always'. "
					. 'See Docs → Operations → Security → Running Behind a Proxy.',
		};
	}

	private function peerAddress(ServerRequestInterface $request): string
	{
		$peer = $request->getServerParams()['REMOTE_ADDR'] ?? '';

		return is_string($peer) && $peer !== '' ? $peer : self::UNKNOWN;
	}

	private function shouldTrustHeaders(string $peer): bool
	{
		return match ($this->config->trustProxyHeaders) {
			self::TRUST_ALWAYS => true,
			self::TRUST_NEVER  => false,
			// No peer address at all is not evidence of a proxy. Without this
			// the UNKNOWN placeholder reads as private — 0.0.0.0 is a reserved
			// address — and a server that failed to populate REMOTE_ADDR would
			// trust whatever headers the caller sent.
			default => $peer !== self::UNKNOWN && $this->isPrivateAddress($peer),
		};
	}

	private function isIpAddress(string $value): bool
	{
		return filter_var($value, FILTER_VALIDATE_IP) !== false;
	}

	/**
	 * A peer that is private, loopback or link-local means something on this
	 * host or LAN forwarded the request — nginx, Apache, a container network.
	 * A public peer means the client reached us directly and anything it sent
	 * is its own claim about itself.
	 */
	private function isPrivateAddress(string $peer): bool
	{
		if (!$this->isIpAddress($peer)) {
			return false;
		}

		// FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE makes validation FAIL for
		// private and reserved addresses, so a failure here is the positive
		// result: loopback (127/8, ::1), RFC 1918, link-local, unique-local.
		return filter_var(
			$peer,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) === false;
	}
}

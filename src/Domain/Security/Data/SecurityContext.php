<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Data;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Support\Config;

/** Where the audit is running from: the site's own origin (when known) and server software. */
readonly class SecurityContext
{
	public function __construct(
		public string $baseUrl,
		public string $host,
		public bool $https,
		public bool $fromWeb,
		public string $serverSoftware,
	) {
	}

	/**
	 * The scheme comes from `is_https` (which honors X-Forwarded-Proto) as well
	 * as the request URI, so a site behind an HTTPS proxy or load balancer is
	 * checked, and probed, as the HTTPS site visitors see.
	 */
	public static function fromRequest(ServerRequestInterface $request, Config $config): self
	{
		$uri    = $request->getUri();
		$https  = $uri->getScheme() === 'https' || $config->isHttps;
		$scheme = $https ? 'https' : 'http';
		$host   = $uri->getHost();
		$port   = $uri->getPort();
		// A proxy that terminates TLS talks to the backend on 80; that port isn't the public one.
		$port   = in_array($port, [80, 443], true) ? null : $port;
		$base   = $host === '' ? '' : $scheme . '://' . $host . ($port !== null ? ':' . $port : '');

		return new self(
			$base,
			$host,
			$https,
			true,
			(string)($request->getServerParams()['SERVER_SOFTWARE'] ?? ''),
		);
	}

	public static function fromCli(Config $config): self
	{
		$unknown = $config->domain === 'unknown' || $config->url === '';
		$url     = $unknown ? '' : rtrim($config->url, '/');
		$host    = $unknown ? '' : (string)parse_url($url, PHP_URL_HOST);

		return new self($url, $host, str_starts_with($url, 'https://'), false, '');
	}

	/** No host is known (the CLI before any web visit), so host-dependent checks can't decide. */
	public function hostUnknown(): bool
	{
		return trim($this->host) === '';
	}

	/** A development or non-routable host: localhost, a bare IP, `.localhost`, `.test` or `example.*`. */
	public function isLocalHost(): bool
	{
		$host = strtolower(trim($this->host));
		if ($host === '') {
			return false;
		}

		return Config::isNonRoutableHost($host)
			|| str_ends_with($host, '.test')
			|| str_starts_with($host, 'example.');
	}

	/** Unknown software (the CLI) counts as Apache so we don't assume Nginx. */
	public function isApache(): bool
	{
		if ($this->serverSoftware === '') {
			return true;
		}

		return stripos($this->serverSoftware, 'apache') !== false
			|| stripos($this->serverSoftware, 'litespeed') !== false;
	}
}

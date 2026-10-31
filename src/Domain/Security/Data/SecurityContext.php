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

	public static function fromRequest(ServerRequestInterface $request): self
	{
		$uri    = $request->getUri();
		$scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'http';
		$host   = $uri->getHost();
		$port   = $uri->getPort();
		$base   = $host === '' ? '' : $scheme . '://' . $host . ($port !== null ? ':' . $port : '');

		return new self(
			$base,
			$host,
			$scheme === 'https',
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

	public function isLocalHost(): bool
	{
		$host = strtolower($this->host);

		return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
			|| str_ends_with($host, '.test')
			|| str_ends_with($host, '.localhost')
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

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

final class CloudflareProvider extends AbstractVideoProvider
{
	public function id(): string
	{
		return 'cloudflare';
	}

	public function matches(string $url): bool
	{
		return $this->match($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		[$code, $id] = $this->match($url) ?? ['', ''];

		// Short "watch.cloudflarestream.com/{id}" form has no customer code,
		// so there is no derivable iframe/thumbnail URL — the short link is
		// itself the embed URL, and no thumbnail is possible.
		if ($code === '') {
			return new VideoInfo('cloudflare', $id, $url, '', null);
		}

		$base = "https://customer-{$code}.cloudflarestream.com/{$id}";

		return new VideoInfo('cloudflare', "{$code}/{$id}", "{$base}/iframe", "{$base}/thumbnails/thumbnail.jpg", null);
	}

	/** @return array{0:string,1:string}|null */
	private function match(string $url): ?array
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)($parts['path'] ?? '');

		if (preg_match('/^customer-([a-z0-9]+)\.cloudflarestream\.com$/', $host, $hostMatch) === 1
			&& preg_match('#^/([A-Za-z0-9]+)/(?:watch|iframe)/?$#', $path, $pathMatch) === 1
		) {
			return [$hostMatch[1], $pathMatch[1]];
		}

		if ($host === 'watch.cloudflarestream.com' && preg_match('#^/([A-Za-z0-9]+)/?$#', $path, $pathMatch) === 1) {
			return ['', $pathMatch[1]];
		}

		return null;
	}
}

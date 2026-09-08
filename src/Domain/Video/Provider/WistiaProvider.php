<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

final class WistiaProvider extends AbstractVideoProvider
{
	public function id(): string
	{
		return 'wistia';
	}

	public function matches(string $url): bool
	{
		return $this->videoId($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		$id = (string)$this->videoId($url);

		return new VideoInfo('wistia', $id, "https://fast.wistia.net/embed/iframe/{$id}", '', 'https://fast.wistia.com/oembed?url=' . rawurlencode($url));
	}

	private function videoId(string $url): ?string
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)($parts['path'] ?? '');

		if ($host === 'fast.wistia.net' && preg_match('#^/embed/iframe/([A-Za-z0-9]+)/?$#', $path, $m) === 1) {
			return $m[1];
		}

		if (preg_match('/^[a-z0-9-]+\.wistia\.com$/', $host) === 1 && preg_match('#^/medias/([A-Za-z0-9]+)/?$#', $path, $m) === 1) {
			return $m[1];
		}

		return null;
	}
}

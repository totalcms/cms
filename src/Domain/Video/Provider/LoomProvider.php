<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

final class LoomProvider extends AbstractVideoProvider
{
	public function id(): string
	{
		return 'loom';
	}

	public function matches(string $url): bool
	{
		return $this->videoId($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		$id = (string)$this->videoId($url);

		return new VideoInfo('loom', $id, "https://www.loom.com/embed/{$id}", '', 'https://www.loom.com/v1/oembed?url=' . rawurlencode($url));
	}

	private function videoId(string $url): ?string
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)($parts['path'] ?? '');

		if (!in_array($host, ['loom.com', 'www.loom.com'], true)) {
			return null;
		}

		if (preg_match('#^/(?:share|embed)/([A-Za-z0-9]+)/?$#', $path, $m) === 1) {
			return $m[1];
		}

		return null;
	}
}

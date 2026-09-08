<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

final class LividProvider extends AbstractVideoProvider
{
	private const ID = '[A-Za-z0-9_-]+';

	public function id(): string
	{
		return 'livid';
	}

	public function matches(string $url): bool
	{
		return $this->videoId($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		$id = (string)$this->videoId($url);

		return new VideoInfo('livid', $id, "https://livid.com/embed/{$id}", '', 'https://livid.com/oembed?url=' . rawurlencode($url) . '&format=json');
	}

	private function videoId(string $url): ?string
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)($parts['path'] ?? '');

		if (!in_array($host, ['livid.com', 'www.livid.com'], true)) {
			return null;
		}

		if (preg_match('#^/(?:watch|video|embed)/(' . self::ID . ')/?$#', $path, $m) === 1) {
			return $m[1];
		}

		return null;
	}
}

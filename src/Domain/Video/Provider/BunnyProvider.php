<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

final class BunnyProvider extends AbstractVideoProvider
{
	public function id(): string
	{
		return 'bunny';
	}

	public function matches(string $url): bool
	{
		return $this->ids($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		[$library, $id] = $this->ids($url) ?? ['', ''];
		$videoId        = "{$library}/{$id}";

		// Bunny Stream oEmbed endpoint per the spec's provider table — not
		// network-verified at implementation time (see task report).
		return new VideoInfo('bunny', $videoId, "https://iframe.mediadelivery.net/embed/{$videoId}", '', 'https://video.bunnycdn.com/OEmbed?url=' . rawurlencode($url));
	}

	/** @return array{0:string,1:string}|null */
	private function ids(string $url): ?array
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)($parts['path'] ?? '');

		if ($host !== 'iframe.mediadelivery.net') {
			return null;
		}

		if (preg_match('#^/(?:play|embed)/(\d+)/([A-Za-z0-9-]+)/?$#', $path, $m) === 1) {
			return [$m[1], $m[2]];
		}

		return null;
	}
}

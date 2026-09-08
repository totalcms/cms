<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

/**
 * Publitio (publit.io) — media hosting with its own video.js player page.
 *
 * Every file is served as `{host}/file/[{transform}/][{folder}/…]{id}.{ext}`,
 * where `{host}` is `media.publit.io` or the account's own custom domain
 * (verified against a real account: `media.weaversspace.com`). The player
 * page is the `.html` extension, optionally with `?player={id}` selecting a
 * player setup, and that is the link the dashboard's Embed URL hands out.
 * Because the host is not fixed, matching is on the path shape alone.
 *
 * Thumbnail: the page's own poster is the same path as a `.jpg` with a
 * `w_1280/` transform inserted after `/file/`. Built here rather than taken
 * from oEmbed, whose `thumbnail_url` is a 300×200 centre crop. oEmbed still
 * supplies the title and the real dimensions; the endpoint is undocumented
 * but advertised by the page (`media.publit.io/oembed`, no trailing slash —
 * the slashed form redirects, and the HTTP client does not follow redirects)
 * and resolves custom-domain URLs too.
 *
 * The player page honours no autoplay/loop/muted query parameters, so
 * embedQuery() stays empty; in facade mode the viewer clicks play once more.
 */
final class PublitioProvider extends AbstractVideoProvider
{
	public function id(): string
	{
		return 'publitio';
	}

	public function matches(string $url): bool
	{
		return $this->filePath($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)$this->filePath($url);
		$query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

		$embedUrl  = "https://{$host}/file/{$path}.html{$query}";
		$thumbnail = "https://{$host}/file/w_1280/{$path}.jpg";
		$oembed    = 'https://media.publit.io/oembed?url=' . rawurlencode("https://{$host}/file/{$path}.html") . '&format=json';

		return new VideoInfo('publitio', $path, $embedUrl, $thumbnail, $oembed);
	}

	/**
	 * The path between `/file/` and `.html` — folders included, since a
	 * Publitio id is only unique within its folder.
	 */
	private function filePath(string $url): ?string
	{
		$parts = parse_url($url);
		$host  = (string)($parts['host'] ?? '');
		$path  = (string)($parts['path'] ?? '');

		if ($host === '') {
			return null;
		}

		if (preg_match('#^/file/((?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+)\.html$#i', $path, $m) === 1) {
			return $m[1];
		}

		return null;
	}
}

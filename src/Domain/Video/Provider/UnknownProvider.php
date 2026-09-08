<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

/**
 * Catch-all for any URL no other provider claimed, as long as it doesn't
 * carry an unsafe scheme (javascript:, data:, ftp:, …). Matches a real
 * http(s) URL, a schemeless relative URL (`/embeds/thing.html`), and a
 * protocol-relative one (`//cdn.example.com/page`) — parity with the
 * pre-video-field EmbedBuilder, which rendered a generic iframe for
 * anything that wasn't YouTube/Vimeo/mp3, with no scheme/host requirement.
 * Always placed last in VideoUrlResolver::defaultProviders().
 */
final class UnknownProvider extends AbstractVideoProvider
{
	public function id(): string
	{
		return 'unknown';
	}

	public function matches(string $url): bool
	{
		// Belt and braces: VideoUrlResolver::resolve() already refuses a
		// non-http(s)-scheme URL before any provider is asked, but this must
		// independently reject one too, in case it's ever called directly.
		return !VideoUrlResolver::hasUnsafeScheme($url);
	}

	public function parse(string $url): VideoInfo
	{
		return new VideoInfo('unknown', '', $url, '', null);
	}
}

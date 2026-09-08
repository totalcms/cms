<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

interface VideoProvider
{
	/**
	 * Stable identifier for this provider (e.g. 'youtube', 'vimeo').
	 * Matches VideoInfo::$provider on a successful parse().
	 */
	public function id(): string;

	/**
	 * Whether this provider recognizes the given URL. Cheap and pure —
	 * no network calls.
	 */
	public function matches(string $url): bool;

	/**
	 * Parse a URL this provider has already claimed via matches(). Only
	 * ever called after matches() has returned true for the same URL.
	 */
	public function parse(string $url): VideoInfo;

	/**
	 * Query string (without a leading "?") for embed-time playback options
	 * such as autoplay/loop/muted. Providers that don't support any of
	 * these options return an empty string.
	 *
	 * @param array<string,mixed> $options
	 */
	public function embedQuery(array $options): string;
}

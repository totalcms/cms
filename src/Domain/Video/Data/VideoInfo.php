<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Data;

/**
 * Resolved information about a video URL: which provider claimed it, the
 * normalized video id, the URL to embed, and (when known without a network
 * call) a thumbnail. `oembedEndpoint` is set for providers that need an
 * oEmbed round-trip to fill in the thumbnail/title later.
 */
final readonly class VideoInfo
{
	public function __construct(
		public string $provider,
		public string $videoId,
		public string $embedUrl,
		public string $thumbnail,
		public ?string $oembedEndpoint,
		public string $aspectRatio = '16:9',
	) {
	}
}

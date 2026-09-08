<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Service;

use TotalCMS\Domain\Video\Data\VideoInfo;
use TotalCMS\Domain\Video\Provider\BunnyProvider;
use TotalCMS\Domain\Video\Provider\CloudflareProvider;
use TotalCMS\Domain\Video\Provider\DirectFileProvider;
use TotalCMS\Domain\Video\Provider\LividProvider;
use TotalCMS\Domain\Video\Provider\LoomProvider;
use TotalCMS\Domain\Video\Provider\PublitioProvider;
use TotalCMS\Domain\Video\Provider\UnknownProvider;
use TotalCMS\Domain\Video\Provider\VideoProvider;
use TotalCMS\Domain\Video\Provider\VimeoProvider;
use TotalCMS\Domain\Video\Provider\WistiaProvider;
use TotalCMS\Domain\Video\Provider\YouTubeProvider;

/**
 * Resolves a raw video URL to a VideoInfo by asking each configured
 * provider, in order, whether it recognizes the URL. Pure — no I/O.
 */
final class VideoUrlResolver
{
	/** @var list<VideoProvider> */
	private array $providers;

	/** @param list<VideoProvider> $providers */
	public function __construct(array $providers = [])
	{
		$this->providers = $providers;
	}

	public function resolve(string $url): VideoInfo
	{
		$url = trim(htmlspecialchars_decode($url));

		if (self::hasUnsafeScheme($url)) {
			return new VideoInfo('unknown', '', '', '', null);
		}

		foreach ($this->providers as $provider) {
			if ($provider->matches($url)) {
				return $provider->parse($url);
			}
		}

		return new VideoInfo('unknown', '', '', '', null);
	}

	/**
	 * True only for a URL that carries an explicit scheme other than
	 * http/https — `javascript:`, `data:`, `ftp:`, etc. A schemeless
	 * relative URL (`/videos/clip.mp4`) or a protocol-relative one
	 * (`//cdn.example.com/page`) is NOT unsafe by this definition: neither
	 * has a scheme token to begin with, so they pass through unchanged and
	 * resolve/match exactly as they did before the video field shipped.
	 * Single source of truth for the "never hand a script/data URI to an
	 * iframe/video src" rule — call this from every site that needs it
	 * rather than re-deriving it from a resolved VideoInfo's `embedUrl`.
	 */
	public static function hasUnsafeScheme(string $url): bool
	{
		return preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1
			&& preg_match('#^https?://#i', $url) !== 1;
	}

	/** @return list<VideoProvider> */
	public static function defaultProviders(): array
	{
		return [
			new YouTubeProvider(),
			new VimeoProvider(),
			new LividProvider(),
			new BunnyProvider(),
			new CloudflareProvider(),
			new LoomProvider(),
			new WistiaProvider(),
			new PublitioProvider(),
			new DirectFileProvider(),
			new UnknownProvider(),
		];
	}
}

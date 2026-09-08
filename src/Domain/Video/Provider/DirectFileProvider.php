<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

final class DirectFileProvider extends AbstractVideoProvider
{
	private const EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v', 'ogv'];

	public function id(): string
	{
		return 'file';
	}

	public function matches(string $url): bool
	{
		// Belt and braces: VideoUrlResolver::resolve() already refuses a
		// non-http(s)-scheme URL before any provider is asked, but this must
		// independently reject one too, in case it's ever called directly.
		// A schemeless relative/protocol-relative URL is NOT rejected here —
		// only a URL with an actual scheme other than http/https is.
		if (VideoUrlResolver::hasUnsafeScheme($url)) {
			return false;
		}

		$path      = (string)(parse_url($url, PHP_URL_PATH) ?? '');
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		return in_array($extension, self::EXTENSIONS, true);
	}

	public function parse(string $url): VideoInfo
	{
		return new VideoInfo('file', '', $url, '', null);
	}
}

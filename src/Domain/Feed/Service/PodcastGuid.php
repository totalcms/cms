<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Feed\Service;

use Symfony\Component\Uid\Uuid;

/**
 * The Podcast Index `<podcast:guid>`: a UUIDv5 of the feed URL under the
 * namespace the spec fixes, with the scheme and any trailing slashes removed
 * so the same feed served over http and https shares one identity.
 *
 * @see https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/1.0.md#guid
 */
final class PodcastGuid
{
	private const NAMESPACE = 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6';

	/** uuid5(NAMESPACE, 'example.com/podcast.xml') — pinned by the test so the derivation cannot drift. */
	public const REFERENCE_EXAMPLE = '8d435561-be52-5398-9646-b8b0c9abb123';

	public static function fromFeedUrl(string $url): string
	{
		$name = (string)preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($url));
		$name = rtrim($name, '/');

		return Uuid::v5(Uuid::fromString(self::NAMESPACE), $name)->toRfc4122();
	}
}

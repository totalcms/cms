<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Data;

/**
 * Whether the file the public /imageworks route would serve for a set of
 * params is already in the ImageWorks cache.
 */
enum CacheState: string
{
	/** The rendered variant is already in the cache. */
	case Cached = 'cached';

	/** The variant would be rendered on its next request. */
	case Missing = 'missing';

	/** The params resolve to the original file: no Glide work, nothing to cache. */
	case Original = 'original';

	/** Both an image and a text watermark: the second pass is re-made per request. */
	case Uncacheable = 'uncacheable';
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

/**
 * Base class for VideoProvider implementations. Supplies the default
 * embedQuery() (no supported playback options) so most providers only need
 * to implement id(), matches() and parse().
 */
abstract class AbstractVideoProvider implements VideoProvider
{
	/** @param array<string,mixed> $options */
	public function embedQuery(array $options): string
	{
		return '';
	}
}

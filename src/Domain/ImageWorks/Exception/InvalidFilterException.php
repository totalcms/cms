<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Exception;

/**
 * An ImageWorks filter recipe that cannot be registered: an unknown step,
 * the wrong number of arguments or a value out of range. The registry logs
 * it and refuses the filter.
 */
final class InvalidFilterException extends \InvalidArgumentException
{
	public static function forFilter(string $name, string $reason): self
	{
		return new self(sprintf('Invalid ImageWorks filter "%s": %s', $name, $reason));
	}
}

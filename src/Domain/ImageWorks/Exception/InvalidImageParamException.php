<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Exception;

/**
 * An ImageWorks query parameter whose value no real page would send: the
 * public routes answer it with a 400 before any image work is done.
 */
final class InvalidImageParamException extends \InvalidArgumentException
{
	public static function forKey(string $key): self
	{
		return new self(sprintf('Invalid ImageWorks parameter: %s', $key));
	}
}

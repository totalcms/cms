<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Exception;

/**
 * A create found its id already on disk. A DomainException, with the message
 * callers have always matched, so code that catches that keeps working.
 */
final class ObjectExistsException extends \DomainException
{
	public function __construct(string $collection, string $id)
	{
		parent::__construct(sprintf('Object with id %s already exists in %s', $id, $collection));
	}
}

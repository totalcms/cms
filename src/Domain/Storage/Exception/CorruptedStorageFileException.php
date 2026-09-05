<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Storage\Exception;

/**
 * A stored JSON file exists but cannot be decoded.
 *
 * Raised in place of the serializer's own NotEncodableValueException, whose
 * message is a bare "Syntax error" with no indication of which file is at
 * fault. Being a type of ours also lets callers that must not fail — a boolean
 * "does this exist?" — recognise a corrupt file and answer, instead of
 * propagating a vendor exception out of a predicate.
 */
final class CorruptedStorageFileException extends \RuntimeException
{
	// NOT $file — that is Exception's own property for the source file the
	// exception was raised in, and shadowing it breaks getFile().
	public function __construct(public readonly string $path, string $reason, ?\Throwable $previous = null)
	{
		parent::__construct(sprintf('%s is not valid JSON: %s', $path, $reason), 0, $previous);
	}
}

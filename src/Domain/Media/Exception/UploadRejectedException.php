<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Exception;

/**
 * A file or URL refused before anything was stored, with the reasons, which
 * are safe to show to whoever sent it.
 */
final class UploadRejectedException extends \RuntimeException
{
	/** @param list<string> $errors */
	public function __construct(public readonly array $errors)
	{
		parent::__construct(implode(' ', $errors));
	}
}

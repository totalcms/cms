<?php

namespace TotalCMS\Domain\License\Exception;

/**
 * License-related exception.
 */
class LicenseException extends \Exception
{
	public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}
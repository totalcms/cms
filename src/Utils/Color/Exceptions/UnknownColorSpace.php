<?php

declare(strict_types=1);

namespace TotalCMS\Utils\Color\Exceptions;

class UnknownColorSpace extends \Exception
{
	public function __construct(
		?string $space    = null,
		int $code     = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct(
			message  : $space !== null ? "Unknown color space: $space" : 'Unknown color space',
			code     : $code,
			previous : $previous,
		);
	}
}

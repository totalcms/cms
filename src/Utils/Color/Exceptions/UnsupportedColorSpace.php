<?php

declare(strict_types=1);

namespace TotalCMS\Utils\Color\Exceptions;

class UnsupportedColorSpace extends \Exception
{
	public function __construct(
		string $space,
		int $code     = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct(
			message  : "The color space \"$space\" is not supported",
			code     : $code,
			previous : $previous,
		);
	}
}

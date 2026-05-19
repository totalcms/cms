<?php

declare(strict_types=1);

namespace TotalCMS\Utils\Color\Exceptions;

class MissingColorValue extends \Exception
{
	public function __construct(
		string $name,
		int $code     = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct(
			message  : "Color value \"$name\" is missing",
			code     : $code,
			previous : $previous,
		);
	}
}

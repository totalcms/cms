<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

final readonly class FormAction
{
	public function __construct(
		public string $name,
		public string $route,
		public string $label,
	) {
	}
}

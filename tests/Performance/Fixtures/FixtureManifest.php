<?php

declare(strict_types=1);

namespace Tests\Performance\Fixtures;

final readonly class FixtureManifest
{
	public function __construct(
		public int $objects,
		public int $pages,
		public int $seed,
		public string $phpVersion,
		public string $generatedAt,
		public string $tcmsVersion,
	) {
	}

	public function toJson(): string
	{
		return (string)json_encode(get_object_vars($this), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	}
}

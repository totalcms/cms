<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

readonly class StarterManifest
{
	public string $name;
	public string $description;
	public string $version;

	/** @param array<string,mixed> $data */
	public function __construct(array $data, public string $directory)
	{
		$this->name        = (string)($data['name'] ?? 'Unknown');
		$this->description = (string)($data['description'] ?? '');
		$this->version     = (string)($data['version'] ?? '1.0.0');
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'name'        => $this->name,
			'description' => $this->description,
			'version'     => $this->version,
		];
	}
}

<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class DepotData extends PropertyData
{
	/** @var array<array<string,mixed>> */
	public array $files;
	public bool $protected;
	public PasswordData $password;

	/** @param array<string,mixed> $depot */
	public function __construct(public array $depot = [])
	{
		$this->protected = $depot['protected'] ?? true;
		$this->password  = new PasswordData($depot['password'] ?? '');
		$this->files     = $depot['files'] ?? [];
	}

	/** @return array<string,mixed> */
	public function transform(): array
	{
		return [
			'password'   => $this->password->transform(),
			'protected'  => $this->protected,
			'files'      => $this->files,
		];
	}
}

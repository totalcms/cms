<?php

namespace TotalCMS\Domain\Property\Data;

class DepotData extends PropertyData
{
	/** @var array<FileData|FolderData> */
	public array $files = [];
	public bool $protected;
	public PasswordData $password;

	/** @param array<string,mixed> $depot */
	public function __construct(public array $depot = [], public array $settings = [])
	{
		$this->protected = $depot['protected'] ?? true;
		$this->password  = new PasswordData($depot['password'] ?? '');
		$this->files     = FolderData::buildFolder($depot['files'] ?? []);
	}

	/** @return array<string,mixed> */
	public function transform(): array
	{
		return [
			'password'   => $this->password->transform(),
			'protected'  => $this->protected,
			'files'      => array_map(fn($file) => $file->transform(), $this->files),
		];
	}
}

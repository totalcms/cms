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
		// Determine default protected value:
		// 1. If explicitly set in data, use that
		// 2. Otherwise, check for protectedByCollection setting
		// 3. Otherwise, default to true
		$defaultProtected = $settings['protectedByCollection'] ?? true;
		$this->protected  = $depot['protected'] ?? $defaultProtected;

		$this->password = new PasswordData($depot['password'] ?? '');
		$this->files    = FolderData::buildFolder($depot['files'] ?? []);
	}

	/** @return array<string,mixed> */
	public function transform(): array
	{
		return [
			'password'   => $this->password->transform(),
			'protected'  => $this->protected,
			'files'      => array_map(fn (FileData|FolderData $file): array => $file->transform(), $this->files),
		];
	}

	public function __toString(): string
	{
		$json = json_encode($this->transform());
		if ($json === false) {
			return '';
		}

		return $json;
	}
}

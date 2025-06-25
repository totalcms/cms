<?php

namespace TotalCMS\Domain\Property\Data;

class FolderData extends PropertyData
{
	public string $name;
	/** @var array<FileData|FolderData> */
	public array $files = [];

	/** @param array<array<string,mixed>> $files */
	public function __construct(string $name, array $files = [])
	{
		$this->name  = $name;
		$this->files = self::buildFolder($files);
	}

	/**
	 * @param array<array<string,mixed>> $files
	 *
	 * @return array<FileData|FolderData>
	 * */
	public static function buildFolder(array $files): array
	{
		$folder = [];

		foreach ($files as $file) {
			if (!isset($file['mime'])) {
				continue;
			}
			if ($file['mime'] === 'folder') {
				$folder[] = new FolderData($file['name'], $file['files']);
				continue;
			}
			$folder[] = new FileData($file);
		}

		return $folder;
	}

	/** @return array<string,mixed> */
	public function transform(): array
	{
		return [
			'name'  => $this->name,
			'mime'  => 'folder',
			'files' => array_map(fn ($file) => $file->transform(), $this->files),
		];
	}
}

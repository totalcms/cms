<?php

namespace TotalCMS\Domain\Property\Data;

class FolderData extends PropertyData
{
	/** @var array<FileData|FolderData> */
	public array $files = [];

	/** @param array<array<string,mixed>> $files */
	public function __construct(public string $name = '', array $files = [])
	{
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
				// A blank-named folder has no directory of its own — its path
				// resolves to this one — and deleting it wiped this whole
				// folder. Older admin builds could write one, so hoist its
				// contents here rather than keep a folder nobody can address.
				if (trim((string)($file['name'] ?? '')) === '') {
					array_push($folder, ...self::buildFolder($file['files'] ?? []));
					continue;
				}
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
			'files' => array_map(fn (FileData|FolderData $file): array => $file->transform(), $this->files),
		];
	}
}

<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class FileData extends PropertyData implements \Stringable
{
	public ListData $tags;
	public PasswordData $password;
	public DateData $uploadDate;
	public bool $protected;
	public string $mime;
	public string $label;
	public string $name;
	public string $ext;
	public string $download;
	public string $comments;
	public int $size;
	public int $count;

	/** @param array<string,mixed> $file */
	public function __construct(array $file = [], public array $settings = [])
	{
		// Determine default protected value:
		// 1. If explicitly set in data, use that
		// 2. Otherwise, check for protectedByCollection setting
		// 3. Otherwise, default to true
		$defaultProtected = $settings['protectedByCollection'] ?? true;
		$this->protected  = $file['protected'] ?? $defaultProtected;

		$this->name     = $file['name'] ?? '';
		$this->ext      = pathinfo($this->name, PATHINFO_EXTENSION);
		$this->download = empty($file['download']) ? $this->name : $file['download'];
		$this->mime     = $file['mime'] ?? '';
		$this->comments = $file['comments'] ?? '';
		$this->size     = intval($file['size'] ?? 0);
		$this->count    = intval($file['count'] ?? 0);
		$this->tags     = new ListData($file['tags'] ?? []);
		$this->password = new PasswordData($file['password'] ?? '');

		$uploadDate       = empty($file['uploadDate']) ? date('c') : $file['uploadDate'];
		$this->uploadDate = new DateData($uploadDate);
	}

	/** @return array<string,mixed> */
	public function transform(): array
	{
		return [
			'tags'       => $this->tags->transform(),
			'password'   => $this->password->transform(),
			'uploadDate' => $this->uploadDate->transform(),
			'protected'  => $this->protected,
			'mime'       => $this->mime,
			'download'   => $this->download,
			'name'       => $this->name,
			'ext'        => $this->ext,
			'comments'   => $this->comments,
			'size'       => $this->size,
			'count'      => $this->count,
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

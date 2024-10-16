<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class FileData extends PropertyData
{
	public ListData $tags;
	public PasswordData $password;
	public DateData $uploadDate;
	public bool $protected;
	public string $mime;
	public string $label;
	public string $name;
	public string $filename;
	public string $comments;
	public int $size;

	/** @param array<string,mixed> $file */
	public function __construct(array $file = [])
	{
		$this->protected = $file['protected'] ?? true;
		$this->filename  = $file['filename'] ?? '';
		$this->name      = $file['name'] ?? $this->filename;
		$this->mime      = $file['mime'] ?? '';
		$this->comments  = $file['comments'] ?? '';
		$this->size      = $file['size'] ?? 0;
		$this->tags      = new ListData($file['tags'] ?? []);
		$this->password  = new PasswordData($file['password'] ?? '');

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
			'filename'   => $this->filename,
			'name'       => $this->name,
			'comments'   => $this->comments,
			'size'       => $this->size,
		];
	}
}

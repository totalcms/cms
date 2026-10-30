<?php

namespace TotalCMS\Domain\Property\Data;

use TotalCMS\Domain\Property\Service\MediaMetaPolicy;

/**
 * String type property data.
 */
class ImageData extends PropertyData implements \Stringable
{
	public ListData $tags;
	public DateData $uploadDate;
	/** @var array<string,string|int> */
	public array $exif;
	/** @var array<string,int> */
	public array $focalpoint;
	/** @var array<string> */
	public array $palette;
	/** @var array<string,string> Who made the image and where it came from; empty when there is no credit. */
	public array $credit;
	public string $alt;
	public string $mime;
	public string $link;
	public string $name;
	public string $hash;
	public int $size;
	public int $width;
	public int $height;
	public bool $featured;

	public const DEFAULT_FOCALPOINT = [
		'x' => 50,
		'y' => 50,
	];

	/** @param array<string,mixed> $file */
	public function __construct(array $file = [], public array $settings = [])
	{
		$this->alt        = $file['alt'] ?? '';
		$this->credit     = MediaMetaPolicy::cleanCredit($file['credit'] ?? []);
		$this->exif       = $file['exif'] ?? ['nodata' => ''];
		$this->featured   = $file['featured'] ?? false;
		$this->focalpoint = $file['focalpoint'] ?? self::DEFAULT_FOCALPOINT;
		$this->hash       = $file['hash'] ?? '';
		$this->height     = intval($file['height'] ?? 0);
		$this->link       = $file['link'] ?? '';
		$this->mime       = $file['mime'] ?? '';
		$this->name       = $file['name'] ?? '';
		$this->palette    = $file['palette'] ?? [];
		$this->size       = intval($file['size'] ?? 0);
		$this->tags       = new ListData($file['tags'] ?? []);
		$this->width      = intval($file['width'] ?? 0);

		$uploadDate       = empty($file['uploadDate']) ? date('c') : $file['uploadDate'];
		$this->uploadDate = new DateData($uploadDate);

		if (isset($this->exif['date'])) {
			$date               = new DateData($this->exif['date']);
			$this->exif['date'] = $date->transform();
		}
	}

	/** @return array<string,mixed> */
	public function transform(): array
	{
		$data = [
			'alt'        => $this->alt,
			'exif'       => $this->exif,
			'featured'   => $this->featured,
			'focalpoint' => $this->focalpoint,
			'hash'       => $this->hash,
			'height'     => $this->height,
			'link'       => $this->link,
			'mime'       => $this->mime,
			'name'       => $this->name,
			'palette'    => $this->palette,
			'size'       => $this->size,
			'tags'       => $this->tags->transform(),
			'uploadDate' => $this->uploadDate->transform(),
			'width'      => $this->width,
		];

		// Only an image with a credit carries the key, so every other image keeps its stored shape.
		if ($this->credit !== []) {
			$data['credit'] = $this->credit;
		}

		return $data;
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

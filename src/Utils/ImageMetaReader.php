<?php

namespace TotalCMS\Utils;

use PHPExif\Enum\ReaderType as ExifReaderType;
use PHPExif\Exif;
use PHPExif\Reader\Reader as ExifReader;

class ImageMetaReader
{
	/** @return array<string,string|int> */
	public static function getBasicImageData(string $imagepath): array
	{
		$imageData = getimagesize($imagepath);
		if (!is_array($imageData)) {
			return [];
		}

		return [
			'mime'   => $imageData['mime'],
			'width'  => $imageData[0],
			'height' => $imageData[1],
		];
	}

	private static function floatOrNull(string|float|int|bool $value): ?float
	{
		if (!is_bool($value)) {
			// Remove any non-numeric characters
			$value = preg_replace('/[^0-9.]/', '', (string)$value);
			if (is_numeric($value)) {
				return floatval($value);
			}
		}

		return null;
	}

	private static function shutterSpeed(string|bool $speed): ?string
	{
		if (is_bool($speed)) {
			return null;
		}
		if (!str_starts_with($speed, '1/')) {
			return '1/' . $speed;
		}

		return $speed;
	}

	/** @return array<string,mixed> */
	public static function getMetaData(string $imagepath): array
	{
		$readerType = extension_loaded('imagick') ? ExifReaderType::IMAGICK : ExifReaderType::NATIVE;
		$exifReader = ExifReader::factory($readerType);
		$exif       = $exifReader->read($imagepath);

		if (!$exif instanceof Exif) {
			return self::getBasicImageData($imagepath);
		}

		$date = $exif->getCreationDate();
		if ($date instanceof \DateTime) {
			$date = $date->format('c');
		}

		$data = [
			// Exposure Data
			'aperture'     => self::floatOrNull($exif->getAperture()),
			'iso'          => self::floatOrNull($exif->getIso()),
			'shutterSpeed' => self::shutterSpeed($exif->getExposure()),
			// Camera Data
			'make'        => $exif->getMake(),
			'camera'      => $exif->getCamera(),
			'lens'        => $exif->getLens(),
			'focalLength' => self::floatOrNull($exif->getFocalLength()),
			// Meta Data
			'author'      => $exif->getAuthor(),
			'description' => $exif->getDescription(),
			// 'keywords'    => $exif->getKeywords(),
			'copyright'   => $exif->getCopyright(),
			'title'       => $exif->getTitle(),
			'date'        => $date,
			// GPS Data
			'longitude'   => $exif->getLongitude() === false ? null : strval($exif->getLongitude()),
			'latitude'    => $exif->getLatitude() === false ? null : strval($exif->getLatitude()),
			'altitude'    => $exif->getAltitude() === false ? null : strval($exif->getAltitude()),
			'country'     => $exif->getCountry(),
			'state'       => $exif->getState(),
			'city'        => $exif->getCity(),
			'sublocation' => $exif->getSublocation(),
		];
		// fitler out any null values
		$data     = array_filter($data);
		$keywords = $exif->getKeywords();

		return array_filter([
			'exif'   => $data,
			'tags'   => is_array($keywords) ? array_values($keywords) : [],
			'alt'    => $data['title'] ?? $data['description'] ?? '',
			'mime'   => $exif->getMimeType(),
			'width'  => intval($exif->getWidth()),
			'height' => intval($exif->getHeight()),
		]);
	}
}

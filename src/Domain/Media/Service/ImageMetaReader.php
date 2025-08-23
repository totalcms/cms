<?php

namespace TotalCMS\Domain\Media\Service;

/**
 * Image metadata reader using native PHP functions.
 * Replaces lychee-org/php-exif for PHP 8.4 compatibility.
 */
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

	private static function floatOrNull(mixed $value): ?float
	{
		if ($value === null || is_bool($value)) {
			return null;
		}
		
		// Remove any non-numeric characters
		$value = preg_replace('/[^0-9.]/', '', (string)$value);
		if (is_numeric($value)) {
			return floatval($value);
		}

		return null;
	}

	private static function shutterSpeed(mixed $speed): ?string
	{
		if (is_bool($speed) || $speed === null) {
			return null;
		}
		
		$speed = (string)$speed;
		if (str_contains($speed, '/')) {
			return $speed;
		}
		
		// Convert decimal to fraction (e.g., "0.008" -> "1/125")
		if (is_numeric($speed) && floatval($speed) > 0) {
			$fraction = 1 / floatval($speed);
			return '1/' . round($fraction);
		}
		
		return $speed;
	}

	/** @param array<int,string> $coord */
	private static function parseGpsCoordinate(array $coord, string $ref): ?float
	{
		if (empty($coord) || count($coord) < 3) {
			return null;
		}

		// Convert DMS (degrees, minutes, seconds) to decimal
		$degrees = self::parseGpsFraction($coord[0]);
		$minutes = self::parseGpsFraction($coord[1]);
		$seconds = self::parseGpsFraction($coord[2]);

		if ($degrees === null) {
			return null;
		}

		$decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
		
		// Apply hemisphere (S and W are negative)
		if (in_array($ref, ['S', 'W'])) {
			$decimal = -$decimal;
		}

		return $decimal;
	}

	private static function parseGpsFraction(string $fraction): ?float
	{
		if (str_contains($fraction, '/')) {
			[$numerator, $denominator] = explode('/', $fraction);
			if (intval($denominator) > 0) {
				return floatval($numerator) / floatval($denominator);
			}
		}
		
		return is_numeric($fraction) ? floatval($fraction) : null;
	}

	private static function formatDate(mixed $dateString): ?string
	{
		if (empty($dateString) || !is_string($dateString)) {
			return null;
		}

		try {
			// Try to parse common EXIF date formats
			$date = \DateTime::createFromFormat('Y:m:d H:i:s', $dateString);
			if ($date === false) {
				$date = new \DateTime($dateString);
			}
			return $date->format('c');
		} catch (\Exception) {
			return null;
		}
	}

	/** @return array<string,mixed> */
	public static function getMetaData(string $imagepath): array
	{
		// Start with basic image data
		$basicData = self::getBasicImageData($imagepath);
		if (empty($basicData)) {
			return [];
		}

		// Try to read EXIF data if the extension is available
		$exifData = [];
		if (extension_loaded('exif') && function_exists('exif_read_data')) {
			try {
				$exif = exif_read_data($imagepath, 'ANY_TAG', true);
				if ($exif !== false) {
					$exifData = $exif;
				}
			} catch (\Exception) {
				// Fallback to basic data on error
			}
		}

		// Try to read XMP data for additional metadata
		$xmpData = self::extractXmpData($imagepath);

		// Extract metadata from EXIF data
		$data = [
			// Exposure Data
			'aperture'     => self::floatOrNull($exifData['EXIF']['FNumber'] ?? $exifData['COMPUTED']['ApertureFNumber'] ?? null),
			'iso'          => self::floatOrNull($exifData['EXIF']['ISOSpeedRatings'] ?? null),
			'shutterSpeed' => self::shutterSpeed($exifData['EXIF']['ExposureTime'] ?? null),
			// Camera Data
			'make'        => trim($exifData['IFD0']['Make'] ?? '') ?: null,
			'camera'      => trim(($exifData['IFD0']['Make'] ?? '') . ' ' . ($exifData['IFD0']['Model'] ?? '')) ?: null,
			'lens'        => trim($xmpData['lens'] ?? $exifData['EXIF']['LensModel'] ?? $exifData['EXIF']['LensInfo'] ?? '') ?: null,
			'focalLength' => self::floatOrNull($exifData['EXIF']['FocalLength'] ?? null),
			// Meta Data
			'author'      => trim($exifData['IFD0']['Artist'] ?? $exifData['EXIF']['Artist'] ?? '') ?: null,
			'description' => trim($exifData['IFD0']['ImageDescription'] ?? $exifData['COMPUTED']['UserComment'] ?? '') ?: null,
			'copyright'   => trim($exifData['IFD0']['Copyright'] ?? '') ?: null,
			'title'       => trim($exifData['IFD0']['DocumentName'] ?? $exifData['IFD0']['ImageDescription'] ?? '') ?: null,
			'date'        => self::formatDate($exifData['EXIF']['DateTimeOriginal'] ?? $exifData['IFD0']['DateTime'] ?? null),
			// GPS Data
			'longitude'   => isset($exifData['GPS']['GPSLongitude'], $exifData['GPS']['GPSLongitudeRef']) 
				? self::parseGpsCoordinate($exifData['GPS']['GPSLongitude'], $exifData['GPS']['GPSLongitudeRef'])
				: null,
			'latitude'    => isset($exifData['GPS']['GPSLatitude'], $exifData['GPS']['GPSLatitudeRef'])
				? self::parseGpsCoordinate($exifData['GPS']['GPSLatitude'], $exifData['GPS']['GPSLatitudeRef'])
				: null,
			'altitude'    => isset($exifData['GPS']['GPSAltitude'])
				? self::parseGpsFraction($exifData['GPS']['GPSAltitude'])
				: null,
			// Location data is typically not in EXIF, would need external geocoding
			'country'     => null,
			'state'       => null,
			'city'        => null,
			'sublocation' => null,
		];

		// Filter out null values
		$data = array_filter($data, fn($value) => $value !== null);

		// Extract keywords from multiple sources (IPTC, XMP, EXIF)
		$keywords = self::extractKeywords($exifData, $xmpData);

		return array_filter([
			'exif'   => $data,
			'tags'   => $keywords,
			'alt'    => $data['title'] ?? $data['description'] ?? '',
			'mime'   => $basicData['mime'],
			'width'  => $basicData['width'],
			'height' => $basicData['height'],
		]);
	}

	/**
	 * Extract XMP metadata from image file.
	 * XMP data contains additional metadata that EXIF doesn't capture.
	 * 
	 * @return array<string,mixed>
	 */
	private static function extractXmpData(string $imagepath): array
	{
		$xmpData = [];
		
		try {
			$contents = file_get_contents($imagepath);
			if ($contents === false) {
				return $xmpData;
			}
			
			// Look for XMP metadata block
			if (preg_match('/<x:xmpmeta.*?<\/x:xmpmeta>/s', $contents, $matches)) {
				$xmp = $matches[0];
				
				// Extract lens information from XMP
				// aux:Lens is the primary XMP lens field
				if (preg_match('/aux:Lens="([^"]+)"/i', $xmp, $lensMatch)) {
					$xmpData['lens'] = trim($lensMatch[1]);
				}
				
				// Fallback to other lens fields if aux:Lens not found
				if (empty($xmpData['lens'])) {
					if (preg_match('/exif:LensModel="([^"]+)"/i', $xmp, $lensMatch)) {
						$xmpData['lens'] = trim($lensMatch[1]);
					} elseif (preg_match('/<aux:Lens>(.*?)<\/aux:Lens>/i', $xmp, $lensMatch)) {
						$xmpData['lens'] = trim($lensMatch[1]);
					}
				}
				
				// Extract other useful XMP data for future enhancement
				if (preg_match('/photoshop:Credit="([^"]+)"/i', $xmp, $creditMatch)) {
					$xmpData['credit'] = trim($creditMatch[1]);
				}
				
				if (preg_match('/photoshop:Headline="([^"]+)"/i', $xmp, $headlineMatch)) {
					$xmpData['headline'] = trim($headlineMatch[1]);
				}
				
				if (preg_match('/xmp:Rating="([^"]+)"/i', $xmp, $ratingMatch)) {
					$xmpData['rating'] = trim($ratingMatch[1]);
				}
				
				// Extract keywords from dc:subject 
				if (preg_match('/<dc:subject>(.*?)<\/dc:subject>/s', $xmp, $subjectMatch)) {
					// Extract individual keywords from RDF bag/seq
					if (preg_match_all('/<rdf:li>(.*?)<\/rdf:li>/', $subjectMatch[1], $keywordMatches)) {
						$xmpData['keywords'] = array_map('trim', $keywordMatches[1]);
					}
				}
			}
		} catch (\Exception) {
			// Return empty array on any error
		}
		
		return $xmpData;
	}

	/**
	 * Extract keywords from multiple metadata sources.
	 * Combines IPTC, XMP, and EXIF keyword data into a single array.
	 * 
	 * @param array<string,mixed> $exifData
	 * @param array<string,mixed> $xmpData
	 * @return array<string>
	 */
	private static function extractKeywords(array $exifData, array $xmpData): array
	{
		$keywords = [];
		
		// 1. Extract from IPTC data (traditional method)
		if (isset($exifData['APP13']['Keywords'])) {
			$iptcKeywords = is_array($exifData['APP13']['Keywords']) 
				? $exifData['APP13']['Keywords'] 
				: [$exifData['APP13']['Keywords']];
			$keywords = array_merge($keywords, $iptcKeywords);
		}
		
		// 2. Extract from IPTC using iptcparse if available
		if (function_exists('iptcparse') && isset($exifData['APP13'])) {
			$iptc = iptcparse($exifData['APP13']);
			if ($iptc) {
				// IPTC 2:25 = Keywords
				if (isset($iptc['2#025'])) {
					$keywords = array_merge($keywords, $iptc['2#025']);
				}
				// IPTC 2:20 = Supplemental Categories  
				if (isset($iptc['2#020'])) {
					$keywords = array_merge($keywords, $iptc['2#020']);
				}
			}
		}
		
		// 3. Extract from XMP dc:subject (modern standard)
		if (isset($xmpData['keywords']) && is_array($xmpData['keywords'])) {
			$keywords = array_merge($keywords, $xmpData['keywords']);
		}
		
		// Clean up keywords: trim whitespace, remove empty values, make unique
		$keywords = array_unique(array_filter(array_map('trim', $keywords)));
		
		// Sort alphabetically for consistent output
		sort($keywords);
		
		return $keywords;
	}
}

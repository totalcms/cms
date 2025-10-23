<?php

namespace TotalCMS\Domain\Media\Service;

/**
 * Service for converting HEIC/HEIF images to JPEG for web compatibility.
 *
 * HEIC is the default image format for iOS devices but is not supported by
 * most web browsers. This service automatically converts HEIC files to JPEG
 * when they are uploaded.
 */
class HeicConverter
{
	/**
	 * Check if a file is a HEIC/HEIF image.
	 *
	 * @param string $filepath Path to the file
	 */
	public function isHeicFile(string $filepath): bool
	{
		$extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

		return in_array($extension, ['heic', 'heif'], true);
	}

	/**
	 * Convert HEIC/HEIF image to JPEG.
	 *
	 * @param string $sourcePath Path to the source HEIC file
	 * @param string $destinationPath Path where the JPEG should be saved (optional)
	 * @param int $quality JPEG quality (1-100, default 92)
	 *
	 * @return array<string,mixed> Result with 'success' boolean, 'path' string, and optional 'error' string
	 */
	public function convertToJpeg(string $sourcePath, ?string $destinationPath = null, int $quality = 92): array
	{
		// Verify source file exists
		if (!file_exists($sourcePath)) {
			return [
				'success' => false,
				'error'   => 'Source file does not exist',
			];
		}

		// Verify it's a HEIC file
		if (!$this->isHeicFile($sourcePath)) {
			return [
				'success' => false,
				'error'   => 'File is not a HEIC/HEIF image',
			];
		}

		// If no destination provided, use same name with .jpg extension
		if ($destinationPath === null) {
			$destinationPath = preg_replace('/\.(heic|heif)$/i', '.jpg', $sourcePath);
		}

		// Ensure destinationPath is a string (preg_replace can return null on error)
		if (!is_string($destinationPath)) {
			$destinationPath = str_replace(['.heic', '.heif', '.HEIC', '.HEIF'], '.jpg', $sourcePath);
		}

		// Check if ImageMagick is available
		if (!$this->isImageMagickAvailable()) {
			return [
				'success' => false,
				'error'   => 'ImageMagick is not available on this server',
			];
		}

		// Convert using ImageMagick
		$result = $this->convertWithImageMagick($sourcePath, $destinationPath, $quality);

		return $result;
	}

	/**
	 * Check if ImageMagick is available.
	 */
	private function isImageMagickAvailable(): bool
	{
		// Try to find ImageMagick binary
		$which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
		exec("{$which} convert 2>&1", $output, $returnVar);

		if ($returnVar === 0) {
			return true;
		}

		// Also try 'magick' command (ImageMagick 7+)
		exec("{$which} magick 2>&1", $output, $returnVar);

		return $returnVar === 0;
	}

	/**
	 * Convert HEIC to JPEG using ImageMagick.
	 *
	 * @param string $sourcePath Source HEIC file
	 * @param string $destinationPath Destination JPEG file
	 * @param int $quality JPEG quality (1-100)
	 *
	 * @return array<string,mixed>
	 */
	private function convertWithImageMagick(string $sourcePath, string $destinationPath, int $quality): array
	{
		// Escape paths for shell command
		$source      = escapeshellarg($sourcePath);
		$destination = escapeshellarg($destinationPath);
		$quality     = max(1, min(100, $quality)); // Ensure quality is between 1-100

		// Try 'magick' command first (ImageMagick 7+)
		$command = "magick {$source} -quality {$quality} {$destination} 2>&1";
		exec($command, $output, $returnVar);

		// If magick command failed, try convert command (ImageMagick 6)
		if ($returnVar !== 0) {
			$command = "convert {$source} -quality {$quality} {$destination} 2>&1";
			exec($command, $output, $returnVar);
		}

		if ($returnVar !== 0) {
			return [
				'success' => false,
				'error'   => 'Failed to convert HEIC to JPEG: ' . implode("\n", $output),
			];
		}

		// Verify the output file was created
		if (!file_exists($destinationPath)) {
			return [
				'success' => false,
				'error'   => 'Conversion completed but output file was not created',
			];
		}

		return [
			'success'      => true,
			'path'         => $destinationPath,
			'original'     => $sourcePath,
			'size'         => filesize($destinationPath),
			'size_reduced' => filesize($sourcePath) - filesize($destinationPath),
		];
	}

	/**
	 * Convert HEIC and delete the original file.
	 *
	 * @param string $sourcePath Path to the source HEIC file
	 * @param int $quality JPEG quality (1-100, default 92)
	 *
	 * @return array<string,mixed> Result with 'success' boolean, 'path' string, and optional 'error' string
	 */
	public function convertAndReplace(string $sourcePath, int $quality = 92): array
	{
		$result = $this->convertToJpeg($sourcePath, null, $quality);

		if ($result['success']) {
			// Delete the original HEIC file
			unlink($sourcePath);
		}

		return $result;
	}
}

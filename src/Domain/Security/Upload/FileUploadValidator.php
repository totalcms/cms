<?php

namespace TotalCMS\Domain\Security\Upload;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Comprehensive file upload security validator.
 * Prevents malicious file uploads, path traversal, and other security issues.
 */
final class FileUploadValidator
{
	/**
	 * Maximum file sizes by category (in bytes).
	 */
	private const MAX_FILE_SIZES = [
		'image'    => 10 * 1024 * 1024,    // 10MB for images
		'video'    => 100 * 1024 * 1024,   // 100MB for videos
		'file'     => 50 * 1024 * 1024,     // 50MB for general files
		'document' => 20 * 1024 * 1024, // 20MB for documents
	];

	/**
	 * Allowed file extensions by category.
	 */
	private const ALLOWED_EXTENSIONS = [
		'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'],
		'video'    => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'm4v'],
		'audio'    => ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a', 'wma'],
		'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp'],
		'archive'  => ['zip', 'tar', 'gz', 'rar', '7z'],
		'file'     => ['csv', 'json', 'xml', 'html', 'css', 'js', 'md'],
	];

	/**
	 * Allowed MIME types by category.
	 */
	private const ALLOWED_MIME_TYPES = [
		'image' => [
			'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
			'image/svg+xml', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon',
		],
		'video' => [
			'video/mp4', 'video/avi', 'video/quicktime', 'video/x-msvideo',
			'video/x-flv', 'video/webm', 'video/x-matroska',
		],
		'audio' => [
			'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/aac',
			'audio/flac', 'audio/mp4', 'audio/x-ms-wma',
		],
		'document' => [
			'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'text/plain', 'application/rtf', 'application/vnd.oasis.opendocument.text',
		],
		'archive' => [
			'application/zip', 'application/x-tar', 'application/gzip',
			'application/x-rar-compressed', 'application/x-7z-compressed',
		],
		'file' => [
			'text/csv', 'application/json', 'application/xml', 'text/xml',
			'text/html', 'text/css', 'application/javascript', 'text/markdown',
		],
	];

	/**
	 * Dangerous file extensions that should never be allowed.
	 */
	private const DANGEROUS_EXTENSIONS = [
		'php', 'php3', 'php4', 'php5', 'phtml', 'asp', 'aspx', 'jsp', 'jspx',
		'exe', 'bat', 'cmd', 'com', 'scr', 'pif', 'vbs', 'vbe', 'js', 'jar',
		'sh', 'bash', 'csh', 'ksh', 'fish', 'pl', 'py', 'rb', 'cgi', 'htaccess',
	];

	/**
	 * Validate uploaded file against security criteria.
	 *
	 * @param UploadedFileInterface $file Uploaded file
	 * @param string $category File category (image, video, file, etc.)
	 * @param array<string,mixed> $config Optional configuration overrides
	 *
	 * @return array<string,mixed> Validation result with 'valid' boolean and 'errors' array
	 */
	public function validateFile(UploadedFileInterface $file, string $category = 'file', array $config = []): array
	{
		$errors   = [];
		$filename = $file->getClientFilename() ?? 'unknown';

		// 1. Check for upload errors
		if ($file->getError() !== UPLOAD_ERR_OK) {
			$errors[] = $this->getUploadErrorMessage($file->getError());
		}

		// 2. Validate file size
		$maxSize = $config['max_size'] ?? self::MAX_FILE_SIZES[$category] ?? self::MAX_FILE_SIZES['file'];
		if ($file->getSize() > $maxSize) {
			$errors[] = sprintf(
				'File size (%s) exceeds maximum allowed size (%s)',
				$this->formatBytes($file->getSize() ?: 0),
				$this->formatBytes($maxSize)
			);
		}

		// 3. Validate filename
		$sanitizedFilename = $this->sanitizeFilename($filename);
		if ($sanitizedFilename !== $filename) {
			$errors[] = 'Filename contains unsafe characters';
		}

		// 4. Check for dangerous extensions
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (in_array($extension, self::DANGEROUS_EXTENSIONS)) {
			$errors[] = "File extension '.{$extension}' is not allowed for security reasons";
		}

		// 5. Validate file extension against category
		$allowedExtensions = $config['allowed_extensions'] ?? self::ALLOWED_EXTENSIONS[$category] ?? [];
		if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions)) {
			$errors[] = "File extension '.{$extension}' is not allowed for {$category} files";
		}

		// 6. Validate MIME type (if file is uploaded successfully)
		if ($file->getError() === UPLOAD_ERR_OK) {
			$clientMimeType   = $file->getClientMediaType();
			$allowedMimeTypes = $config['allowed_mime_types'] ?? self::ALLOWED_MIME_TYPES[$category] ?? [];

			if (!empty($allowedMimeTypes) && !in_array($clientMimeType, $allowedMimeTypes)) {
				$errors[] = "MIME type '{$clientMimeType}' is not allowed for {$category} files";
			}
		}

		return [
			'valid'              => empty($errors),
			'errors'             => $errors,
			'sanitized_filename' => $sanitizedFilename,
			'file_size'          => $file->getSize(),
			'mime_type'          => $file->getClientMediaType(),
			'extension'          => $extension,
		];
	}

	/**
	 * Validate MIME type against file content (requires file to be saved to disk).
	 *
	 * @param string $filepath Path to uploaded file on disk
	 * @param string $category Expected file category
	 *
	 * @return array<string,mixed> Validation result
	 */
	public function validateMimeTypeFromFile(string $filepath, string $category): array
	{
		if (!file_exists($filepath)) {
			return ['valid' => false, 'errors' => ['File does not exist']];
		}

		// Use PHP's fileinfo to detect actual MIME type
		$finfo        = new \finfo(FILEINFO_MIME_TYPE);
		$detectedMime = $finfo->file($filepath);

		if ($detectedMime === false) {
			return ['valid' => false, 'errors' => ['Could not determine file MIME type']];
		}

		$allowedMimeTypes = self::ALLOWED_MIME_TYPES[$category] ?? [];
		$isValid          = in_array($detectedMime, $allowedMimeTypes);

		return [
			'valid'         => $isValid,
			'errors'        => $isValid ? [] : ["Detected MIME type '{$detectedMime}' does not match expected category '{$category}'"],
			'detected_mime' => $detectedMime,
		];
	}

	/**
	 * Sanitize filename to prevent path traversal and other attacks.
	 */
	public function sanitizeFilename(string $filename): string
	{
		// Remove path components (../, ..\, etc.)
		$filename = basename($filename);

		// Remove or replace dangerous characters
		$filename = (string)preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

		// Prevent hidden files and multiple dots
		$filename = (string)preg_replace('/^\.+/', '', $filename);
		$filename = (string)preg_replace('/\.{2,}/', '.', $filename);

		// Ensure filename is not empty after sanitization
		if (empty($filename)) {
			$filename = 'unnamed_file_' . time();
		}

		// Limit filename length
		if (strlen($filename) > 255) {
			$extension = pathinfo($filename, PATHINFO_EXTENSION);
			$basename  = pathinfo($filename, PATHINFO_FILENAME);
			$filename  = substr($basename, 0, 255 - strlen($extension) - 1) . '.' . $extension;
		}

		return $filename;
	}

	/**
	 * Get available file categories and their configurations.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function getFileCategories(): array
	{
		$categories = [];
		foreach (self::ALLOWED_EXTENSIONS as $category => $extensions) {
			$categories[$category] = [
				'max_size'           => self::MAX_FILE_SIZES[$category] ?? self::MAX_FILE_SIZES['file'],
				'max_size_formatted' => $this->formatBytes(self::MAX_FILE_SIZES[$category] ?? self::MAX_FILE_SIZES['file']),
				'extensions'         => $extensions,
				'mime_types'         => self::ALLOWED_MIME_TYPES[$category],
			];
		}

		return $categories;
	}

	/**
	 * Get human-readable upload error message.
	 */
	private function getUploadErrorMessage(int $error): string
	{
		return match ($error) {
			UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize directive in php.ini',
			UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE directive in HTML form',
			UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
			UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
			UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
			UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
			UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension',
			default               => 'Unknown upload error',
		};
	}

	/**
	 * Format bytes into human-readable format.
	 */
	private function formatBytes(int $bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB'];
		$bytes = max($bytes, 0);
		$pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow   = min($pow, count($units) - 1);

		$bytes /= (1 << (10 * $pow));

		return round($bytes, 2) . ' ' . $units[$pow];
	}
}

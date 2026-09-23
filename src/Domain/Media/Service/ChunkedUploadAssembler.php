<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Service;

use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Support\Config;

/**
 * Receives Dropzone-style chunked uploads into the temp directory and
 * assembles them once the last chunk lands. A single-part upload is the
 * one-chunk case.
 */
readonly class ChunkedUploadAssembler
{
	private const UPLOAD_ERRORS = [
		UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
		UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in HTML form',
		UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
		UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
		UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory',
		UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
		UPLOAD_ERR_EXTENSION  => 'File upload stopped by PHP extension',
	];

	public function __construct(private Config $config)
	{
	}

	/**
	 * Store this part. Returns the assembled file's path when it was the last
	 * part, null while more are expected.
	 *
	 * @param array<string,mixed> $body The request body carrying the chunk counters
	 *
	 * @throws \RuntimeException On a PHP upload error or an unwritable temp file
	 */
	public function receive(UploadedFileInterface $file, array $body): ?string
	{
		$error = $file->getError();
		if ($error !== UPLOAD_ERR_OK) {
			throw new \RuntimeException(self::UPLOAD_ERRORS[$error] ?? 'Unknown upload error (code: ' . $error . ')');
		}

		$chunkIndex  = intval($body['dzchunkindex'] ?? $body['chunkindex'] ?? 0);
		$totalChunks = intval($body['dztotalchunkcount'] ?? $body['totalchunkcount'] ?? 1);
		$filename    = $file->getClientFilename() ?? 'unknown_file';

		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0700, true);
		}

		$file->moveTo($this->chunkPath($filename, $chunkIndex));

		if ($chunkIndex !== $totalChunks - 1) {
			return null;
		}

		return $this->assemble($filename, $totalChunks);
	}

	private function assemble(string $filename, int $totalChunks): string
	{
		$finalPath = $this->config->tmpdir . '/' . $filename;
		$final     = fopen($finalPath, 'wb');
		if ($final === false) {
			throw new \RuntimeException('Unable to open final file for writing:' . $finalPath);
		}

		for ($i = 0; $i < $totalChunks; $i++) {
			$chunkPath = $this->chunkPath($filename, $i);
			$chunk     = fopen($chunkPath, 'rb');
			if ($chunk === false) {
				throw new \RuntimeException('Unable to open chunk file');
			}
			while ($data = fread($chunk, 8192)) {
				fwrite($final, $data);
			}
			fclose($chunk);
			unlink($chunkPath);
		}

		fclose($final);

		return $finalPath;
	}

	private function chunkPath(string $filename, int $chunkIndex): string
	{
		return $this->config->tmpdir . '/' . $filename . '.part' . $chunkIndex;
	}
}

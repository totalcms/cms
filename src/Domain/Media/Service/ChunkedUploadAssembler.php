<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Service;

use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Support\Config;

/**
 * Receives Dropzone-style chunked uploads into the temp directory and
 * assembles them once the last chunk lands. A single-part upload is the
 * one-chunk case.
 */
readonly class ChunkedUploadAssembler
{
	private const PREFIX      = 'upload-';
	private const STALE_AFTER = 86400;

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
	 * @throws UploadRejectedException When the last part lands and an earlier one never did
	 * @throws \RuntimeException       On a PHP upload error or an unwritable temp file
	 */
	public function receive(UploadedFileInterface $file, array $body): ?string
	{
		$error = $file->getError();
		if ($error !== UPLOAD_ERR_OK) {
			throw new \RuntimeException(self::UPLOAD_ERRORS[$error] ?? 'Unknown upload error (code: ' . $error . ')');
		}

		$chunkIndex  = intval($body['dzchunkindex'] ?? $body['chunkindex'] ?? 0);
		$totalChunks = intval($body['dztotalchunkcount'] ?? $body['totalchunkcount'] ?? 1);
		$filename    = basename($file->getClientFilename() ?? 'unknown_file');
		$filename    = $filename === '' ? 'unknown_file' : $filename;

		FileUtils::ensureDirectory($this->config->tmpdir, 0700);
		$this->sweepStale();

		// The client's dzuuid is untrusted: only its hash reaches a path.
		// Legacy clients chunk without a dzuuid: key a multi-chunk upload on its
		// filename so the parts still meet; a single chunk gets a random id.
		$uuid = $body['dzuuid'] ?? null;
		if (is_string($uuid) && $uuid !== '') {
			$id = hash('sha256', $uuid);
		} elseif ($totalChunks > 1) {
			$id = hash('sha256', $filename);
		} else {
			$id = bin2hex(random_bytes(16));
		}
		$dir  = $this->config->tmpdir . '/' . self::PREFIX . $id;
		FileUtils::ensureDirectory($dir, 0700);

		$file->moveTo($this->chunkPath($dir, $filename, $chunkIndex));

		if ($chunkIndex !== $totalChunks - 1) {
			return null;
		}

		return $this->assemble($dir, $filename, $totalChunks);
	}

	/**
	 * Remove the upload folder holding an assembled file. Safe when the file
	 * was already moved away, and a no-op for any path that is not directly
	 * inside an `upload-*` folder of the temp directory.
	 */
	public function cleanup(string $assembledPath): void
	{
		$dir  = dirname($assembledPath);
		$root = realpath($this->config->tmpdir);
		if ($root === false || realpath(dirname($dir)) !== $root) {
			return;
		}
		if (!str_starts_with(basename($dir), self::PREFIX) || !is_dir($dir) || is_link($dir)) {
			return;
		}

		$this->removeDir($dir);
	}

	/** Drop upload folders nobody has touched for a day (abandoned chunked uploads). */
	private function sweepStale(): void
	{
		$cutoff = time() - self::STALE_AFTER;
		foreach (glob($this->config->tmpdir . '/' . self::PREFIX . '*', GLOB_ONLYDIR) ?: [] as $dir) {
			if (!is_link($dir) && (int)filemtime($dir) < $cutoff) {
				$this->removeDir($dir);
			}
		}
	}

	private function removeDir(string $dir): void
	{
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				@unlink($dir . '/' . $entry);
			}
		}
		@rmdir($dir);
	}

	private function assemble(string $dir, string $filename, int $totalChunks): string
	{
		// A last part with no earlier ones can come from a client that lost
		// them, or one sending a single part to look like the end of an upload.
		for ($i = 0; $i < $totalChunks; $i++) {
			if (!is_file($this->chunkPath($dir, $filename, $i))) {
				$this->removeDir($dir);

				throw new UploadRejectedException(['The upload is incomplete.']);
			}
		}

		$finalPath = $dir . '/' . $filename;
		$final     = fopen($finalPath, 'wb');
		if ($final === false) {
			throw new \RuntimeException('Unable to open final file for writing:' . $finalPath);
		}

		for ($i = 0; $i < $totalChunks; $i++) {
			$chunkPath = $this->chunkPath($dir, $filename, $i);
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

	private function chunkPath(string $dir, string $filename, int $chunkIndex): string
	{
		return $dir . '/' . $filename . '.part' . $chunkIndex;
	}
}

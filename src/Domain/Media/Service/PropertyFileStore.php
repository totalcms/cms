<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Service;

use TotalCMS\Domain\Media\Data\StoredFile;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Support\Config;
use TotalCMS\Support\RemoteFileDownloader;

/**
 * Put a file on an object's image, file, gallery or depot field: the one path
 * the admin's upload route and the MCP file tools share, so the two cannot
 * drift.
 *
 * A file arrives either as a local file (an upload, already assembled from
 * its chunks) or as a URL to fetch. A URL the operator typed is fetched as
 * the admin always has; a URL an agent or a dropped payload chose is fetched
 * with `publicOnly`, which can reach public addresses only.
 */
final readonly class PropertyFileStore
{
	public function __construct(
		private SaverFactory $factory,
		private Config $config,
		private HeicConverter $heicConverter,
		private RemoteFileDownloader $downloader,
		private FileUploadValidator $validator,
	) {
	}

	/**
	 * Refuse a filename that could be run on the server (scripts, executables).
	 *
	 * @throws UploadRejectedException
	 */
	public function assertFilename(string $filename): void
	{
		$check = $this->validator->validateFilename($filename);
		if (!$check['valid']) {
			throw new UploadRejectedException($check['errors']);
		}
	}

	/**
	 * Fetch a URL into the temp directory, refusing a script or executable by
	 * its name before anything is downloaded.
	 *
	 * @throws UploadRejectedException when the URL or its filename is refused
	 * @throws \RuntimeException       when the download fails
	 */
	public function fetch(string $url, bool $publicOnly = false): string
	{
		$url = trim($url);
		if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new UploadRejectedException(['Invalid URL: ' . $url]);
		}

		$this->assertFilename(RemoteFileDownloader::filenameFor($url));

		return $publicOnly ? $this->downloader->downloadPublic($url) : $this->downloader->download($url);
	}

	/**
	 * Store a local file on the field. An image or file field is replaced, a
	 * gallery gains one image at the end, and a depot gains the file at
	 * `$subpath` (a folder), exactly as an upload in the admin does.
	 */
	public function store(string $collection, string $id, string $property, string $localPath, ?string $subpath = null): StoredFile
	{
		// HEIC becomes JPEG where it can; a failed conversion keeps the original, to retry later.
		if ($this->heicConverter->isHeicFile($localPath)) {
			$conversion = $this->heicConverter->convertAndReplace($localPath);
			if ($conversion->success) {
				$localPath = (string)$conversion->data['path'];
			}
		}

		$saver  = $this->factory->generateSaverService($collection, $property, $id, $subpath);
		$object = $saver->save($collection, $id, $property, $localPath, $subpath);
		$type   = $saver->type;

		return new StoredFile(
			object: $object,
			type: $type,
			name: $this->storedName($object, $property, $type, $localPath),
			preview: UploadPreviewUrl::build($type, $this->config->api, $collection, $id, $property, $subpath, $object),
		);
	}

	/**
	 * The name the file was stored under: the field's own for an image or a
	 * file, the last gallery image's, and the local name for a depot (which
	 * keeps it).
	 */
	private function storedName(ObjectData $object, string $property, string $type, string $localPath): string
	{
		$value = $object->toArray()[$property] ?? null;

		return match (true) {
			$type === 'gallery' && is_array($value) && array_is_list($value) && $value !== [] => (string)(end($value)['name'] ?? ''),
			in_array($type, ['image', 'file'], true) && is_array($value)                     => (string)($value['name'] ?? ''),
			default                                                                          => basename($localPath),
		};
	}
}

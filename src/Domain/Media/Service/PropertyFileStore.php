<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Service;

use TotalCMS\Domain\Media\Data\StoredFile;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Service\MediaMetaPolicy;
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
		private ObjectPatcher $patcher,
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
			name: $this->storedName($object, $property, $type, $localPath, $subpath),
			preview: UploadPreviewUrl::build($type, $this->config->api, $collection, $id, $property, $subpath, $object),
		);
	}

	/**
	 * Lay descriptive meta (alt, credit, tags…) over the file just stored, as
	 * the admin's edit dialog would: only the type's editable keys, merged by
	 * {@see MediaMetaPolicy}. An image or file field takes it whole, inside a
	 * card or deck item too; a gallery applies it to the new image; a depot to
	 * the new file. Returns the stored file with the object as saved.
	 *
	 * @param array<string,mixed> $meta
	 */
	public function describe(StoredFile $stored, string $collection, string $id, string $property, array $meta, ?string $subpath = null): StoredFile
	{
		$value = $this->fileValue($stored->object, $property, $stored->type, $subpath);
		if ($meta === [] || !is_array($value)) {
			return $stored;
		}

		if (in_array($stored->type, ['gallery', 'depot'], true)) {
			// The same merge the gallery and depot meta routes use, by stored name.
			$patched = $this->patcher->patchObjectPropertyMeta($collection, $id, $property, $stored->name, $meta, $stored->type === 'depot' ? $subpath : null);
		} elseif ($this->isNested($stored->type, $subpath)) {
			// A card child (`child`) or a deck item's child (`item/child`).
			$patched = $this->patcher->patchNestedProperty($collection, $id, $property, (string)$subpath, MediaMetaPolicy::merge($stored->type, $value, $meta));
		} else {
			$patched = $this->patcher->patchObject($collection, $id, ['id' => $id, $property => MediaMetaPolicy::merge($stored->type, $value, $meta)]);
		}

		return new StoredFile($patched, $stored->type, $stored->name, $stored->preview);
	}

	/**
	 * The stored value of the field: the property itself, or for an image or
	 * file inside a card or deck item, the child at `$subpath`. (A gallery's
	 * or depot's subpath is a folder, not a child.).
	 */
	private function fileValue(ObjectData $object, string $property, string $type, ?string $subpath): mixed
	{
		$value = $object->toArray()[$property] ?? null;
		if ($this->isNested($type, $subpath)) {
			foreach (explode('/', (string)$subpath) as $segment) {
				$value = is_array($value) ? ($value[$segment] ?? null) : null;
			}
		}

		return $value;
	}

	private function isNested(string $type, ?string $subpath): bool
	{
		return $subpath !== null && $subpath !== '' && in_array($type, ['image', 'file'], true);
	}

	/**
	 * The name the file was stored under: the field's own for an image or a
	 * file (in a card or deck item too), the last gallery image's, and the local name for a depot (which
	 * keeps it).
	 */
	private function storedName(ObjectData $object, string $property, string $type, string $localPath, ?string $subpath = null): string
	{
		$value = $this->fileValue($object, $property, $type, $subpath);

		return match (true) {
			$type === 'gallery' && is_array($value) && array_is_list($value) && $value !== [] => (string)(end($value)['name'] ?? ''),
			in_array($type, ['image', 'file'], true) && is_array($value)                      => (string)($value['name'] ?? ''),
			default                                                                           => basename($localPath),
		};
	}
}

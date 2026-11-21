<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Serving;

use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * Finds the stored file a route was asked for. Everything a route serves goes
 * through here, so the same rules hold everywhere: the property must be an S3
 * field in the schema, the value a valid pointer, and its key under this
 * install's prefix. With no bucket configured nothing is servable.
 */
final readonly class PointerLocator
{
	public function __construct(
		private S3Settings $settings,
		private ObjectFetcher $objects,
		private S3Fields $fields,
		private KeyBuilder $keys,
	) {
	}

	/**
	 * @param string $fileId the id of one file of an s3depot; '' for an s3file
	 *
	 * @return array{pointer:Pointer, settings:array<string,mixed>}|null null when there is nothing servable
	 */
	public function find(string $collection, string $id, string $property, string $fileId = ''): ?array
	{
		// Without a bucket there is no address to send anyone to.
		$field = $this->settings->isConfigured() ? $this->fields->property($collection, $property) : null;
		if ($field === null) {
			return null;
		}

		try {
			$stored = $this->objects->fetchObject($collection, $id)->toArray();
		} catch (\Throwable) {
			return null;
		}

		foreach (S3Fields::pointers($stored[$property] ?? null, $field['field']) as $pointer) {
			$wanted = $field['field'] === S3Fields::DEPOT ? $pointer->id === $fileId : $fileId === '';
			if ($wanted) {
				return $this->keys->isInsidePrefix($pointer->key) ? ['pointer' => $pointer, 'settings' => $field['settings']] : null;
			}
		}

		return null;
	}
}

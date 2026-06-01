<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\CodeData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Persists `code` fields flagged `external: true` to a sibling file on disk
 * (`<collection>/<id>/<property>/<property>.<ext>`) instead of inline in the
 * object JSON, and hydrates them back on read.
 *
 * The on-disk object JSON keeps the field blank; the canonical value lives in
 * the sidecar file. To the rest of the system (admin form, Twig, JumpStart,
 * Sync) the field still looks like a normal string property — the
 * externalization is an internal storage detail handled at the repository seam.
 */
readonly class ExternalFieldStore
{
	private const EXT_BY_MODE = [
		'php'        => 'php',
		'twig'       => 'twig',
		'javascript' => 'js',
		'js'         => 'js',
		'css'        => 'css',
		'html'       => 'html',
		'json'       => 'json',
	];

	public function __construct(
		private StorageAdapterInterface $filesystem,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * Resolve which schema properties are external code fields.
	 *
	 * @param array<string,mixed> $properties Schema `->properties`
	 *
	 * @return array<string,string> propertyName => file extension
	 */
	public function externalFields(array $properties): array
	{
		$external = [];

		foreach ($properties as $name => $definition) {
			if (!is_array($definition)) {
				continue;
			}

			$settings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];

			if (($definition['field'] ?? '') !== 'code') {
				continue;
			}
			if (($settings['external'] ?? false) !== true) {
				continue;
			}

			$mode                  = (string)($settings['mode'] ?? '');
			$external[(string)$name] = self::EXT_BY_MODE[$mode] ?? 'txt';
		}

		return $external;
	}

	/**
	 * Build the relative on-disk path for an external field's sidecar file.
	 */
	public function sidecarPath(string $collection, string $id, string $property, string $ext): string
	{
		return PathUtils::buildPath(
			collection: $collection,
			objectID: $id,
			property: $property,
			filename: $property . '.' . $ext,
		);
	}

	/**
	 * Write each external field's value to its sidecar file.
	 *
	 * @return list<string> property names that were externalized (to blank in JSON)
	 */
	public function persist(string $collection, ObjectData $object): array
	{
		$fields    = $this->externalFields($this->schemaFetcher->fetchSchemaForCollection($collection)->properties);
		$persisted = [];

		foreach ($fields as $name => $ext) {
			$property = $object->properties->get($name);
			if ($property === null) {
				continue;
			}

			$this->filesystem->write($this->sidecarPath($collection, $object->id, $name, $ext), (string)$property);
			$persisted[] = $name;
		}

		return $persisted;
	}

	/**
	 * Read each external field's value from its sidecar file back into the object.
	 */
	public function hydrate(string $collection, ObjectData $object): void
	{
		$fields = $this->externalFields($this->schemaFetcher->fetchSchemaForCollection($collection)->properties);

		foreach ($fields as $name => $ext) {
			$property = $object->properties->get($name);
			if (!$property instanceof CodeData) {
				continue;
			}

			$path = $this->sidecarPath($collection, $object->id, $name, $ext);
			if ($this->filesystem->fileExists($path)) {
				$property->code = $this->filesystem->read($path);
			}
		}
	}
}

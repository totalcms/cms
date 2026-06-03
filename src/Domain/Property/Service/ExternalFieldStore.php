<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Extension\Service\DangerousCodeScanner;
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
		private DangerousCodeScanner $scanner,
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

			$mode                    = (string)($settings['mode'] ?? '');
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

			$value = (string)$property;

			// Reject a PHP handler that reaches for shell/eval primitives before
			// it ever lands on disk. persist() runs before the object JSON write,
			// so a throw here aborts the whole save cleanly. Dual-use file/network
			// calls (file_put_contents, curl_exec, …) remain advisory, not blocked.
			if ($ext === 'php') {
				$blocking = $this->scanner->scanCodeForBlocking($value);
				if ($blocking !== []) {
					throw new \DomainException($this->blockedMessage($name, $blocking));
				}
			}

			$this->filesystem->write($this->sidecarPath($collection, $object->id, $name, $ext), $value);
			$persisted[] = $name;
		}

		return $persisted;
	}

	/**
	 * @param list<array{pattern:string,line:int,snippet:string}> $findings
	 */
	private function blockedMessage(string $field, array $findings): string
	{
		$parts = array_map(
			static fn (array $finding): string => sprintf('%s (line %d)', $finding['pattern'], $finding['line']),
			$findings,
		);

		return sprintf(
			'The "%s" handler was rejected: disallowed code detected — %s. Remove these calls to save.',
			$field,
			implode(', ', $parts),
		);
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

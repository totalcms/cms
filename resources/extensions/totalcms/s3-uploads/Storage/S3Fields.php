<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Storage;

use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Which properties of a collection are S3 fields, read from the schema.
 *
 * Only top-level properties count: an s3 field inside a card or deck is not
 * supported, so it is never uploaded to, served or cleaned up.
 */
final readonly class S3Fields
{
	public const FILE  = 's3file';
	public const DEPOT = 's3depot';

	public function __construct(private SchemaFetcher $schemas)
	{
	}

	/**
	 * @return array<string,array{field:'s3file'|'s3depot', settings:array<string,mixed>}> keyed by property name; empty when the schema cannot be read
	 */
	public function forCollection(string $collection): array
	{
		try {
			$properties = $this->schemas->fetchSchemaForCollection($collection)->properties;
		} catch (\Throwable) {
			return [];
		}

		$fields = [];
		foreach ($properties as $name => $definition) {
			$field = is_array($definition) ? ($definition['field'] ?? null) : null;
			if ($field === self::FILE || $field === self::DEPOT) {
				$fields[(string)$name] = [
					'field'    => $field,
					'settings' => is_array($definition['settings'] ?? null) ? $definition['settings'] : [],
				];
			}
		}

		return $fields;
	}

	/**
	 * Whether the property is stored as json, which a pointer needs. A new
	 * s3 property gets that type on its own; one switched from another field
	 * in the schema editor keeps the type it had, and then cannot hold a file.
	 */
	public function storesJson(string $collection, string $property): bool
	{
		try {
			$definition = $this->schemas->fetchSchemaForCollection($collection)->properties[$property] ?? null;
		} catch (\Throwable) {
			return false;
		}
		if (!is_array($definition)) {
			return false;
		}

		// A saved schema names the type as a reference to its property schema.
		$ref = is_string($definition['$ref'] ?? null) ? basename($definition['$ref'], '.json') : null;

		return ($definition['type'] ?? null) === 'json' || $ref === 'json';
	}

	/** @return array{field:'s3file'|'s3depot', settings:array<string,mixed>}|null null when the property is not an S3 field */
	public function property(string $collection, string $property): ?array
	{
		return $this->forCollection($collection)[$property] ?? null;
	}

	/**
	 * The valid pointers in a stored value: one for an s3file, the list for an s3depot.
	 *
	 * @return list<Pointer>
	 */
	public static function pointers(mixed $value, string $field): array
	{
		if ($field === self::FILE) {
			$pointer = Pointer::fromArray($value);

			return $pointer instanceof Pointer ? [$pointer] : [];
		}

		if ($field !== self::DEPOT || !is_array($value) || !array_is_list($value)) {
			return [];
		}

		$pointers = [];
		foreach ($value as $item) {
			$pointer = Pointer::fromArray($item);
			if ($pointer instanceof Pointer) {
				$pointers[] = $pointer;
			}
		}

		return $pointers;
	}
}

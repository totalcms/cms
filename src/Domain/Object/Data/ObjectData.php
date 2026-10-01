<?php

namespace TotalCMS\Domain\Object\Data;

use Illuminate\Support\Collection;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Data\SlugData;

/**
 * Data collection object.
 */
class ObjectData
{
	// Reserved names that cannot be used for objects
	// these are used in that sub URLs in the admin dashboard
	public const RESERVED_NAMES = [
		'index',
		'add',
		'edit',
		'id',
	];

	public string $id;
	/** @var Collection<string,PropertyData> */
	public Collection $properties;
	protected Serializer $serializer;

	/** @param array<string,mixed> $properties */
	public function __construct(string $id, array $properties)
	{
		$this->id         = SlugData::slugify($id);
		$this->properties = new Collection($properties);
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		$base = ['id' => $this->id];

		// Transform properties
		$properties = $this->properties->map(fn ($property): mixed => $property->transform());

		return array_merge($base, $properties->toArray());
	}

	/**
	 * The object as it may leave the server: toArray() minus every password
	 * property. Stored password values are bcrypt hashes, which only login
	 * and password reset need — they read toArray() internally. API responses
	 * and rendered fragments use this so a hash is never sent to a client,
	 * whatever the collection's read permissions are.
	 *
	 * @return array<string,mixed>
	 */
	public function toArrayWithoutPasswords(): array
	{
		$base = ['id' => $this->id];

		$properties = $this->properties
			->reject(fn ($property): bool => $property instanceof PasswordData)
			->map(fn ($property): mixed => $property->transform());

		return array_merge($base, $properties->toArray());
	}

	/**
	 * Fill in this (stored) object's password hashes for any password key the
	 * incoming full-object payload leaves out.
	 *
	 * For the entry points where the payload comes from outside the server
	 * and was shaped by a read that never includes passwords — REST PUT and
	 * MCP update_object. A full replace would otherwise blank the hash and
	 * lock the user out. Only an absent key is filled: a key that is present,
	 * even empty, is the caller's value. Internal callers build their payload
	 * from toArray(), which already carries the hash, and don't need this.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function carryPasswordsInto(array $data): array
	{
		foreach ($this->properties as $name => $property) {
			if ($property instanceof PasswordData && !array_key_exists($name, $data)) {
				$data[$name] = $property->hash;
			}
		}

		return $data;
	}

	public function toJson(): string
	{
		return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES]);
	}

	/** @return array<string> */
	public function forCsv(): array
	{
		$properties = $this->properties->map(function ($property): string {
			$value = strval($property);

			// Escape newlines for CSV compatibility by converting to literal \n
			// This preserves newlines in a CSV-safe format that can be parsed back
			return str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $value);
		});
		$properties['id'] = $this->id;

		return $properties->toArray();
	}
}

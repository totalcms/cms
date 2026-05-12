<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * JSON property data — stores arbitrary structured data natively instead of
 * round-tripping through a string-of-JSON. transform() returns the PHP-native
 * value so the outer ObjectData::toJson serializer renders the property as a
 * nested object on disk, avoiding `\"`/`\/` escape artifacts.
 *
 * Accepts:
 * - array  : stored as-is (the canonical shape, posted by the JSON field's JS)
 * - string : decoded via json_decode; falls back to the raw string when the
 *            input isn't valid JSON. This keeps legacy data (which used to be
 *            stored as a JSON string) loadable; on next save it's written as
 *            a nested object.
 * - null   : empty value
 */
class JsonData extends PropertyData implements \Stringable
{
	public mixed $data;

	/** @param array<string,mixed> $settings */
	public function __construct(mixed $data = null, public array $settings = [])
	{
		if (is_string($data)) {
			$trimmed = trim($data);
			if ($trimmed === '') {
				$this->data = null;

				return;
			}
			$decoded    = json_decode($trimmed, true);
			$this->data = json_last_error() === JSON_ERROR_NONE ? $decoded : $trimmed;

			return;
		}

		$this->data = $data;
	}

	public function transform(): mixed
	{
		return $this->data;
	}

	public function __toString(): string
	{
		if ($this->data === null) {
			return '';
		}
		if (is_string($this->data)) {
			return $this->data;
		}
		$encoded = json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return $encoded === false ? '' : $encoded;
	}
}

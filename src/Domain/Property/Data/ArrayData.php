<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * Array type property data.
 *
 * Stores a flat list. Two input shapes are accepted: a PHP array (used
 * directly) or a string. Strings are routed through one of two parsers:
 *
 *  - JSON-shaped strings (starting with `[`): decoded as JSON. Required for
 *    fields that carry structured rows — e.g. WebAuthn passkeys, which are
 *    list-of-objects. Without this branch, the form-builder's re-submit of
 *    the existing JSON value would land in `explode(',', $data)` and split
 *    every comma inside the JSON (including commas inside nested arrays and
 *    field separators), shredding the data on save.
 *
 *  - All other strings: comma-CSV explode, the original behavior intended
 *    for simple tag lists like `"red,green,blue"`.
 */
class ArrayData extends PropertyData implements \Stringable
{
	/** @var array<mixed> */
	public array $data;

	/** @param array<mixed>|string $data */
	public function __construct(array|string $data = [], public array $settings = [])
	{
		if (is_string($data)) {
			$data = $this->parseStringInput($data);
		}

		$this->data = $this->repairdata($data);
	}

	/**
	 * @return array<mixed>
	 */
	private function parseStringInput(string $data): array
	{
		if ($data === '') {
			return [];
		}

		// JSON-array shape — decode and use directly. Anchored on the
		// first non-whitespace `[` so we don't mistake a CSV value that
		// happens to contain brackets later in the string.
		$trimmed = ltrim($data);
		if ($trimmed !== '' && $trimmed[0] === '[') {
			$decoded = json_decode($trimmed, true);
			if (is_array($decoded) && array_is_list($decoded)) {
				return $decoded;
			}
		}

		// Tag-list shape.
		return explode(',', $data);
	}

	/**
	 * @param array<mixed> $data
	 *
	 * @return array<mixed>
	 * */
	private function repairdata(array $data): array
	{
		$data = array_filter($data);
		$data = array_values($data);

		if (!$this->verifydata($data)) {
			throw new \InvalidArgumentException('data must be an array list:' . json_encode($data));
		}

		return $data;
	}

	/** @param array<mixed> $data */
	private function verifydata(array $data): bool
	{
		return array_is_list($data);
	}

	/** @return array<mixed> */
	public function transform(): array
	{
		return $this->data;
	}

	public function __toString(): string
	{
		// Comma-join only when every entry is scalar — preserves the original
		// "red,green,blue" CSV output for tag lists. Lists of structured rows
		// (e.g. passkeys) round-trip as JSON, which also survives a subsequent
		// re-parse via the JSON branch in parseStringInput().
		foreach ($this->data as $item) {
			if (!is_scalar($item) && !is_null($item)) {
				return (string)json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		}

		return implode(',', array_map(static fn (bool|float|int|string|null $v): string => (string)$v, $this->data));
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use TotalCMS\Domain\Factory\Service\FactoryImporter;

/**
 * Turns an object definition that mixes static values and faker rules into
 * concrete object data.
 */
readonly class FactoryDataBuilder
{
	public function __construct(private FactoryImporter $factoryImporter)
	{
	}

	/**
	 * For an imported object: real data, in which only image and gallery
	 * rules are honored. JumpStart cannot carry image binaries, so an author
	 * may write those as faker rules — but the collection's factory
	 * definitions are deliberately NOT merged in. Those are test-data rules,
	 * and merging them faker-filled every schema property missing from the
	 * import (random toggles, placeholder images) instead of leaving them to
	 * schema defaults.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	public function forImport(string $collectionId, string $objectId, array $objectData): array
	{
		[$rules, $static] = $this->split($objectData, static fn (string $rule): bool => str_starts_with($rule, 'image') || str_starts_with($rule, 'gallery'));

		return $this->generate($collectionId, $objectId, $rules, $static);
	}

	/**
	 * For a factory entry: test data, so the collection's factory definitions
	 * fill every property the entry leaves unspecified.
	 *
	 * @param array<string,mixed> $factoryData
	 *
	 * @return array<string,mixed>
	 */
	public function forFactory(string $collectionId, string $objectId, array $factoryData): array
	{
		[$rules, $static] = $this->split($factoryData, null);
		$rules            = $this->factoryImporter->mergeFactoryDefinitions($collectionId, $rules);

		return $this->generate($collectionId, $objectId, $rules, $static);
	}

	/**
	 * @param array<string,mixed>        $objectData
	 * @param callable(string):bool|null $accept Which faker rules count as rules; the rest stay static
	 *
	 * @return array{0: array<string,string>, 1: array<string,mixed>}
	 */
	private function split(array $objectData, ?callable $accept): array
	{
		$rules  = [];
		$static = [];

		foreach ($objectData as $property => $value) {
			if (is_string($value) && $this->factoryImporter->isFakerRule($value) && ($accept === null || $accept($value))) {
				$rules[$property] = $value;
				continue;
			}
			$static[$property] = $value;
		}

		return [$rules, $static];
	}

	/**
	 * @param array<string,string> $rules
	 * @param array<string,mixed>  $static
	 *
	 * @return array<string,mixed>
	 */
	private function generate(string $collectionId, string $objectId, array $rules, array $static): array
	{
		return array_merge($this->factoryImporter->generateFakeObject($collectionId, $rules, $objectId), $static);
	}
}

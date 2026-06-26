<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Data;

/**
 * Tristate export filters: null = all, [] = none, list<string> = those ids.
 * collectionFilter scopes definitions; objectFilter scopes object data.
 */
readonly class JumpStartExportOptions
{
	/**
	 * @param list<string>|null $schemaFilter
	 * @param list<string>|null $collectionFilter
	 * @param list<string>|null $objectFilter
	 * @param list<string>|null $templateFilter
	 */
	public function __construct(
		public ?array $schemaFilter = null,
		public ?array $collectionFilter = null,
		public ?array $objectFilter = null,
		public ?array $templateFilter = null,
	) {
	}
}

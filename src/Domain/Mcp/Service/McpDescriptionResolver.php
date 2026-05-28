<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;

/**
 * Resolves AI-targeted descriptions for collections and schema properties.
 *
 * The `mcp.description` field exists at two levels (collection-level via the
 * mcp card; property-level via each property's optional `mcp` sub-key). Both
 * fall back to existing operator-facing fields when unset, so customers get
 * usable AI descriptions out of the box without having to write everything
 * twice for two audiences:
 *
 *   - Collection: mcp.description → CollectionData.description → null
 *   - Property:   mcp.description → property.help → property.label → null
 *
 * Returning null (not an empty string) signals "no description available"
 * to callers so they can fall back to schema id / property name if desired.
 */
readonly class McpDescriptionResolver
{
	public function forCollection(CollectionData $collection): ?string
	{
		$mcpDescription = trim((string)($collection->mcp['description'] ?? ''));
		if ($mcpDescription !== '') {
			return $mcpDescription;
		}

		$generalDescription = trim($collection->description);
		if ($generalDescription !== '') {
			return $generalDescription;
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $property A property definition from a schema's
	 *                                       `properties` map (or a collection's
	 *                                       `properties` override). May or may
	 *                                       not contain `mcp`, `help`, `label`.
	 */
	public function forProperty(array $property): ?string
	{
		$mcp            = is_array($property['mcp'] ?? null) ? $property['mcp'] : [];
		$mcpDescription = trim((string)($mcp['description'] ?? ''));
		if ($mcpDescription !== '') {
			return $mcpDescription;
		}

		$help = trim((string)($property['help'] ?? ''));
		if ($help !== '') {
			return $help;
		}

		$label = trim((string)($property['label'] ?? ''));
		if ($label !== '') {
			return $label;
		}

		return null;
	}
}

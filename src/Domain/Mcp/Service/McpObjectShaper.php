<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;

/**
 * The shape an object takes when a read tool returns it: properties the
 * schema marks `mcp.expose: false` are dropped first (so nothing is rendered
 * only to be thrown away), styledtext properties are rendered in the agent's
 * chosen format, and the public URL is added. Six tools and the collection
 * resource each carried this loop.
 */
final readonly class McpObjectShaper
{
	public function __construct(
		private McpSchemaResolver $schemaResolver,
		private ContentRenderer $contentRenderer,
		private ObjectUrlBuilder $urlBuilder,
	) {
	}

	/**
	 * @param array<string,mixed> $object
	 * @param string|null         $format `markdown`, `html` or `text`; null leaves content as stored.
	 *
	 * @return array<string,mixed>
	 */
	public function shape(array $object, CollectionData $collection, ?string $format): array
	{
		$object = $this->strip($object, $collection);

		if ($format !== null) {
			foreach ($this->schemaResolver->renderableProperties($collection) as $field) {
				if (isset($object[$field])) {
					$object[$field] = $this->contentRenderer->render($object[$field], $format);
				}
			}
		}

		$object['url'] = $this->urlBuilder->buildUrl($collection, $object);

		return $object;
	}

	/**
	 * Drop the properties the schema does not expose to MCP.
	 *
	 * @param array<string,mixed> $object
	 *
	 * @return array<string,mixed>
	 */
	public function strip(array $object, CollectionData $collection): array
	{
		foreach ($this->schemaResolver->nonExposedProperties($collection) as $field) {
			unset($object[$field]);
		}

		return $object;
	}
}

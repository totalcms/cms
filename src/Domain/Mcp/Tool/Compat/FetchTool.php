<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Compat;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\ObjectTitleResolver;
use TotalCMS\Domain\Mcp\Tool\Content\GetObjectTool;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * `fetch(id)` — ChatGPT-compatibility tool. ChatGPT requires a tool named
 * exactly `fetch` returning {id,title,text,url,metadata}. Decodes the composite
 * "{collection}:{objectId}" id from `search`, delegates to GetObjectTool (reusing
 * its persona/draft/strip/render pipeline), and reshapes the flat object into a
 * document. Exempt from mcp.toolPrefix so the literal name survives.
 */
readonly class FetchTool
{
	public function __construct(
		private GetObjectTool $getObjectTool,
		private CollectionFetcher $collectionFetcher,
		private McpSchemaResolver $schemaResolver,
		private ObjectTitleResolver $titleResolver,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'fetch',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'Fetch',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
			outputSchema: $this->outputSchema(),
			exemptFromPrefix: true,
		));
	}

	/**
	 * @return array{id: string, title: string, text: string, url: string, metadata: array<string,mixed>}
	 */
	public function handler(string $id): array
	{
		$parts = CompositeObjectId::split($id);
		if ($parts === null) {
			throw $this->notFound($id);
		}
		[$collectionId, $objectId] = $parts;

		// GetObjectTool throws an opaque not-found for unknown/draft/inaccessible
		// objects — preserves draft-existence opacity. Let it propagate.
		$object = $this->getObjectTool->handler(collection: $collectionId, id: $objectId);

		$collection    = $this->collectionFetcher->fetchCollection($collectionId);
		$titleProperty = $collection instanceof CollectionData
			? $this->schemaResolver->forCollection($collection)['titleProperty']
			: '';
		$renderable = $collection instanceof CollectionData
			? $this->schemaResolver->renderableProperties($collection)
			: [];

		$title = $this->titleResolver->resolve($object, $titleProperty);
		$url   = is_scalar($object['url'] ?? null) ? (string)$object['url'] : '';

		// Body: concatenate rendered content properties under headings. The
		// object is already rendered to the chosen format by GetObjectTool.
		$textParts = [];
		foreach ($renderable as $prop) {
			if (isset($object[$prop]) && is_scalar($object[$prop]) && trim((string)$object[$prop]) !== '') {
				$textParts[] = '## ' . ucfirst($prop) . "\n\n" . (string)$object[$prop];
			}
		}
		$text = implode("\n\n", $textParts);

		// metadata: remaining exposed scalar fields not already surfaced.
		$consumed = array_merge(['id', 'url'], $renderable);
		$metadata = [];
		foreach ($object as $key => $value) {
			if (in_array($key, $consumed, true)) {
				continue;
			}
			if (is_scalar($value)) {
				$metadata[$key] = $value;
			}
		}

		// Fall back to a scalar dump when no renderable body exists.
		if ($text === '') {
			$dump = [];
			foreach ($metadata as $k => $v) {
				$dump[] = '## ' . ucfirst((string)$k) . "\n\n" . (string)$v;
			}
			$text = implode("\n\n", $dump);
		}

		return [
			'id'       => $id,
			'title'    => $title,
			'text'     => $text,
			'url'      => $url,
			'metadata' => $metadata,
		];
	}

	private function notFound(string $id): ToolCallException
	{
		return new ToolCallException(sprintf(
			'No document found for id "%s". Get valid ids from the `search` tool.',
			$id,
		));
	}

	private function description(): string
	{
		return implode(' ', [
			'Fetch the full document for a search result id.',
			'Returns {id, title, text, url, metadata}; `text` is the readable body for citation.',
			'Pass an id returned by the `search` tool.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id'],
			'additionalProperties' => false,
			'properties'           => [
				'id' => [
					'type'        => 'string',
					'description' => 'A document id returned by the `search` tool.',
				],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function outputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id', 'title', 'text', 'url'],
			'additionalProperties' => true,
			'properties'           => [
				'id'       => ['type' => 'string'],
				'title'    => ['type' => 'string'],
				'text'     => ['type' => 'string'],
				'url'      => ['type' => 'string'],
				'metadata' => ['type' => 'object'],
			],
		];
	}
}

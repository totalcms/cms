<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resources;

use TotalCMS\Domain\Mcp\Tools\Content\GetObjectTool;

/**
 * Handler for `resources/read tcms://{collection}/{id}`.
 *
 * Delegates to GetObjectTool::handler() so the persona check, draft-hiding,
 * non-exposed-field stripping, and content-renderer pipeline live in exactly
 * one place. This class is the URI rewrap: it takes the flat-map object the
 * tool returns and packages it as the SDK's resources/read content envelope.
 *
 * Mounted into ResourceRegistry by CollectionResourceRegistrar (Task A6); this
 * class never registers itself.
 */
readonly class CollectionObjectResource
{
	public function __construct(
		private GetObjectTool $getObjectTool,
	) {
	}

	/**
	 * @return array<string,mixed> Resource-read response shape: {contents: [{uri, mimeType, text}]}
	 */
	public function read(string $collection, string $id): array
	{
		$object = $this->getObjectTool->handler(collection: $collection, id: $id);

		$json = json_encode($object, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode resource payload.');
		}

		return [
			'contents' => [
				[
					'uri'      => \sprintf('tcms://%s/%s', $collection, $id),
					'mimeType' => 'application/json',
					'text'     => $json,
				],
			],
		];
	}
}

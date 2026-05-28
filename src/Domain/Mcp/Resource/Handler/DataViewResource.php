<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Handler;

use TotalCMS\Domain\Mcp\Tool\Content\GetViewTool;

/**
 * Handler for `resources/read tcms://view/{id}`.
 *
 * Delegates to GetViewTool to keep one code path for "fetch one view" across
 * the resource + tool surfaces. The handler-side persona enforcement, 50-item
 * cap, and freeform output shape all live in GetViewTool — this class is the
 * URI rewrap layer.
 *
 * Mounted into ResourceRegistry by DataViewResourceRegistrar; never registers
 * itself.
 */
readonly class DataViewResource
{
	public function __construct(
		private GetViewTool $getViewTool,
	) {
	}

	/**
	 * @return array<string,mixed> Resource-read shape: {contents: [{uri, mimeType, text}]}
	 */
	public function read(string $id): array
	{
		// GetViewTool::handler() returns a flat result dict ({items, total,
		// truncated, hint?}). Wrap it in the SDK's resources/read envelope.
		$result = $this->getViewTool->handler(id: $id);

		$json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode view resource payload.');
		}

		return [
			'contents' => [
				[
					'uri'      => \sprintf('tcms://view/%s', $id),
					'mimeType' => 'application/json',
					'text'     => $json,
				],
			],
		];
	}
}

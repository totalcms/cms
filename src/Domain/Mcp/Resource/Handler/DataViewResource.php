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
	 * @return array<string,mixed> Flat resource content: {text, mimeType} — the SDK wraps it
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

		// Flat {text, mimeType} — NOT a {contents: [...]} envelope. The SDK
		// builds the ReadResourceResult itself; returning a pre-built one made
		// it the *text* of the SDK's own envelope, burying the payload two
		// levels deep. See ResourceResultFormatter::format().
		return [
			'text'     => $json,
			'mimeType' => 'application/json',
		];
	}
}

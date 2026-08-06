<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Handler;

use TotalCMS\Domain\Mcp\Tool\Content\GetObjectTool;

/**
 * Handler for `resources/read tcms://{collection}/{id}`.
 *
 * Delegates to GetObjectTool::handler() so the persona check, draft-hiding,
 * group-read gate (Task 10b — GetObjectTool::handler()'s inline
 * PersonaContext::canReadCollection() check), non-exposed-field stripping,
 * and content-renderer pipeline live in exactly one place. This class is the
 * URI rewrap: it takes the flat-map object the tool returns and packages it
 * as the SDK's resources/read content envelope.
 *
 * Correction to task-10-report.md (lines ~29-34): that report claimed this
 * class "inherits" group gating from GetObjectTool as of Task 10. That was
 * inaccurate — Task 9 gave GetObjectTool only a DRAFT-visibility rule
 * (PersonaContext::canReadDrafts()); no group-read check existed anywhere in
 * GetObjectTool until Task 10b added one. Before Task 10b, this class
 * inherited draft-hiding only, same as any AUTHENTICATED caller reaching a
 * `mcp.access: authenticated|public` collection via get_object.
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

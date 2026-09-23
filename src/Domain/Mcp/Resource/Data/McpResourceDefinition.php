<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Data;

/**
 * A single MCP resource with a concrete `tcms://...` (or extension-scheme)
 * URI. Registered in ResourceRegistry, persona-filtered, and handed to the
 * SDK as addResource(...) inside McpServerFactory. The handler is invoked
 * with no arguments on resources/read.
 */
readonly class McpResourceDefinition extends AbstractMcpResource
{
	/**
	 * @param string $uri Concrete URI (e.g. 'tcms://blog/')
	 */
	public function __construct(
		public string $uri,
		string $name,
		string $description,
		string $mimeType,
		string $access,
		\Closure $handler,
		?string $collectionId = null,
	) {
		parent::__construct($name, $description, $mimeType, $access, $handler, $collectionId);
	}
}

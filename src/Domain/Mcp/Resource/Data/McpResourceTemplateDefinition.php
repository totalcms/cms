<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Data;

/**
 * An MCP resource template — a URI pattern like `tcms://blog/{id}` that AI
 * agents fill in to construct concrete URIs for resources/read. One template
 * covers an unbounded set of objects without enumerating them into
 * resources/list. The SDK's URI router extracts the `{name}` segments and
 * passes them to the handler as named arguments.
 */
readonly class McpResourceTemplateDefinition extends AbstractMcpResource
{
	/**
	 * @param string $uriTemplate URI template with `{name}` placeholders (e.g. 'tcms://blog/{id}')
	 */
	public function __construct(
		public string $uriTemplate,
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

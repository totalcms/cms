<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Data;

use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

/**
 * Value object describing an MCP resource template — a URI pattern like
 * `tcms://blog/{id}` that AI agents fill in to construct concrete URIs for
 * resources/read. Distinct from McpResourceDefinition (one concrete URI per
 * registration) in that one template covers an unbounded set of objects
 * without forcing enumeration into resources/list.
 *
 * Handler receives the substituted segment values as named arguments matching
 * the template's `{name}` placeholders. The SDK's URI router does the
 * extraction; we receive parsed args.
 */
readonly class McpResourceTemplateDefinition
{
	/**
	 * @param string      $uriTemplate URI template with `{name}` placeholders (e.g. 'tcms://blog/{id}')
	 * @param string      $name        Human-readable name
	 * @param string      $description Description for AI agents
	 * @param string      $mimeType    Content type the handler will produce
	 * @param string      $access      'admin', 'public', or 'authenticated' (OAuth Bearer with mcp:* scope)
	 * @param \Closure     $handler     Invoked with named args matching template variables
	 * @param string|null $collectionId Collection id this template enumerates objects for, when
	 *                                  it's a collection-scoped template. See McpResourceDefinition::
	 *                                  $collectionId for the full rationale — kept in sync here so
	 *                                  resources/templates/list agrees with resources/list on which
	 *                                  collections an AUTHENTICATED caller can see. Left null for
	 *                                  non-collection templates (data views). As of Task 10b this is
	 *                                  ALSO set on the per-object `tcms://{collection}/{id}` template
	 *                                  (previously left null — see CollectionResourceRegistrar), now
	 *                                  that GetObjectTool (which CollectionObjectResource delegates
	 *                                  to) actually enforces the same read rule at call time, so this
	 *                                  visibility check is no longer stricter than the handler.
	 */
	public function __construct(
		public string $uriTemplate,
		public string $name,
		public string $description,
		public string $mimeType,
		public string $access,
		public \Closure $handler,
		public ?string $collectionId = null,
	) {
	}

	public function isVisibleTo(McpPersona $persona, ?UserAuthority $authority = null): bool
	{
		return match ($persona) {
			McpPersona::ADMIN         => true,
			McpPersona::AUTHENTICATED => ($this->access === 'public' || $this->access === 'authenticated')
				&& $this->authorizedFor($authority),
			McpPersona::PUBLIC_       => $this->access === 'public',
		};
	}

	/**
	 * Same rule as McpResourceDefinition::authorizedFor() — see its docblock
	 * for the Task 10b public-collection-carve-out rationale.
	 */
	private function authorizedFor(?UserAuthority $authority): bool
	{
		if ($this->collectionId === null || $this->access === 'public') {
			return true;
		}

		return $authority instanceof UserAuthority && $authority->canCollection('read', $this->collectionId);
	}
}
